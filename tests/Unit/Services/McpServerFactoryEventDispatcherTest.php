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
