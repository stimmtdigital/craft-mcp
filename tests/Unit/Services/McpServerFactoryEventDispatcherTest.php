<?php

declare(strict_types=1);

use Mcp\Capability\Registry;
use Mcp\Event\ToolListChangedEvent;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Protocol;
use stimmt\craft\Mcp\support\EventDispatcher;

/**
 * McpServerFactory::create() itself needs a booted Craft app (Craft::$app,
 * Mcp::getInstance(), ...), so these tests exercise the exact SDK calls it
 * makes (Registry(dispatcher), Builder::setEventDispatcher()) directly,
 * proving the wiring pattern the factory now uses actually fixes the two
 * bugs: dead Registry events, and false list-changed capabilities.
 */
describe('EventDispatcher wired into the SDK Registry', function () {
    it('fires ToolListChangedEvent through the dispatcher on tool registration', function () {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(ToolListChangedEvent::class, function (object $event) use (&$seen): void {
            $seen[] = $event;
        });

        $registry = new Registry($dispatcher);
        $registry->registerTool(
            new Tool(name: 'demo', title: null, inputSchema: ['type' => 'object'], description: null, annotations: null),
            static fn (): string => 'ok',
        );

        expect($seen)->toHaveCount(1)
            ->and($seen[0])->toBeInstanceOf(ToolListChangedEvent::class);
    });

    it('fires again on unregisterTool', function () {
        $dispatcher = new EventDispatcher();
        $count = 0;
        $dispatcher->addListener(ToolListChangedEvent::class, function () use (&$count): void {
            ++$count;
        });

        $registry = new Registry($dispatcher);
        $registry->registerTool(
            new Tool(name: 'demo', title: null, inputSchema: ['type' => 'object'], description: null, annotations: null),
            static fn (): string => 'ok',
        );
        $registry->unregisterTool('demo');

        expect($count)->toBe(2);
    });
});

describe('Builder::setEventDispatcher() truthfulness', function () {
    it('advertises toolsListChanged true once a real dispatcher is set, matching McpServerFactory::create()', function () {
        $server = Server::builder()
            ->setServerInfo(name: 'Test Server', version: '1.0.0')
            ->setEventDispatcher(new EventDispatcher())
            ->build();

        expect(initializeCapabilities($server)->toolsListChanged)->toBeTrue();
    });

    it('advertises toolsListChanged false without a dispatcher, reproducing the pre-fix bug', function () {
        $server = Server::builder()
            ->setServerInfo(name: 'Test Server', version: '1.0.0')
            ->build();

        expect(initializeCapabilities($server)->toolsListChanged)->toBeFalse();
    });
});

/**
 * Reaches the built InitializeHandler's Configuration via reflection: Server
 * exposes neither Protocol nor Configuration publicly, and simulating a full
 * initialize request/response round trip would add session/transport
 * machinery the capabilities check does not need.
 */
function initializeCapabilities(Server $server): Mcp\Schema\ServerCapabilities {
    $protocol = (new ReflectionProperty(Server::class, 'protocol'))->getValue($server);
    $handlers = (new ReflectionProperty(Protocol::class, 'requestHandlers'))->getValue($protocol);

    foreach ($handlers as $handler) {
        if ($handler instanceof InitializeHandler) {
            return $handler->configuration->capabilities;
        }
    }

    throw new RuntimeException('InitializeHandler not found among the built Protocol request handlers.');
}

/**
 * mcp/sdk 0.7 defers loader-based element loading to the first registry
 * read, and Registry::unregisterTool() silently no-ops for elements that
 * are not loaded yet. McpServerFactory::filterTools() unregisters scoped
 * and privileged tools right after Builder::build(), so it depends on the
 * SDK's documented exception: a registry supplied via setRegistry() is
 * loaded eagerly at build time. These tests pin that contract; if a future
 * SDK release breaks it, scope filtering would silently stop working and
 * this suite must fail loudly.
 */
describe('eager loading of a custom registry (SDK 0.7 contract)', function () {
    $tool = static fn (string $name): Mcp\Schema\Tool => new Mcp\Schema\Tool(
        name: $name,
        title: null,
        inputSchema: ['type' => 'object'],
        description: null,
        annotations: null,
    );

    it('loads a setRegistry() registry eagerly at build time', function () use ($tool) {
        $registry = new Registry(null);
        $loader = new class ($tool('loaded-tool')) implements Mcp\Capability\Registry\Loader\LoaderInterface {
            public function __construct(private readonly Mcp\Schema\Tool $tool) {
            }

            public function load(Mcp\Capability\RegistryInterface $registry): void {
                $registry->registerTool($this->tool, static fn (): string => 'ok');
            }
        };

        Server::builder()
            ->setServerInfo(name: 'test', version: '1.0.0')
            ->setRegistry($registry)
            ->addLoader($loader)
            ->build();

        expect($registry->hasTools())->toBeTrue();
    });

    it('keeps post-build unregistration effective across later reads', function () use ($tool) {
        $registry = new Registry(null);
        $loader = new class ($tool('privileged-tool'), $tool('plain-tool')) implements Mcp\Capability\Registry\Loader\LoaderInterface {
            /** @var list<Mcp\Schema\Tool> */
            private readonly array $tools;

            public function __construct(Mcp\Schema\Tool ...$tools) {
                $this->tools = array_values($tools);
            }

            public function load(Mcp\Capability\RegistryInterface $registry): void {
                foreach ($this->tools as $tool) {
                    $registry->registerTool($tool, static fn (): string => 'ok');
                }
            }
        };

        Server::builder()
            ->setServerInfo(name: 'test', version: '1.0.0')
            ->setRegistry($registry)
            ->addLoader($loader)
            ->build();

        $registry->unregisterTool('privileged-tool');
        $names = array_map(static fn ($t) => $t->name, $registry->getTools()->references);

        expect($names)->toContain('plain-tool')
            ->and($names)->not->toContain('privileged-tool');
    });
});
