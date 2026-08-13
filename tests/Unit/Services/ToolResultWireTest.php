<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/RealCraft.php';

use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use stimmt\craft\Mcp\enums\ResponseFormat;
use stimmt\craft\Mcp\services\McpServerFactory;
use stimmt\craft\Mcp\support\Palette;
use stimmt\craft\Mcp\support\Presenter;
use stimmt\craft\Mcp\support\Renderer;

/**
 * McpServerFactory::create() needs a booted Craft app, so this drives the real
 * SDK CallToolHandler with the same collaborators the factory hands it. It is
 * the end-to-end proof that a tool payload now crosses the wire once: without
 * the Presenter, CallToolHandler emits `content` AND `structuredContent` for
 * every array return.
 */
function callTool(callable $handler, array $inputSchema, array $arguments, bool $present = true): array {
    $registry = new Registry();
    $registry->registerTool(
        new Tool(name: 'demo', title: null, inputSchema: $inputSchema, description: null, annotations: null),
        $handler,
    );

    $inner = new ReferenceHandler();
    $reference = $present ? new Presenter($inner, new Renderer(new Palette(false))) : $inner;

    $result = (new CallToolHandler($registry, $reference))->handle(
        (new CallToolRequest('demo', $arguments))->withId(1),
        new Session(new InMemorySessionStore()),
    );

    expect($result)->toBeInstanceOf(Response::class);

    return json_decode(json_encode($result->result), true);
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

describe('McpServerFactory wiring', function () {
    /**
     * create() itself needs a booted Craft app, so this pins the collaborator
     * it installs: the SDK's own reference handler, wrapped in the Presenter,
     * with a palette read from the install's settings.
     */
    it('builds the presenter around the SDK reference handler', function () {
        $presenter = (new ReflectionMethod(McpServerFactory::class, 'presenter'))
            ->invoke(new McpServerFactory());

        expect($presenter)->toBeInstanceOf(Presenter::class);

        $inner = (new ReflectionProperty(Presenter::class, 'handler'))->getValue($presenter);

        expect($inner)->toBeInstanceOf(ReferenceHandler::class);
    });

    it('passes the reference handler to the builder', function () {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/services/McpServerFactory.php');

        expect($source)->toContain('->setReferenceHandler($this->presenter())');
    });
});
