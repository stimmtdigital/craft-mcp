<?php

declare(strict_types=1);

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use stimmt\craft\Mcp\services\ListChangedInitialize;

/**
 * mcp/sdk 0.9 strips every listChanged flag from the initialize answer, on
 * the grounds that only its new subscriptions/listen streams deliver list
 * changes. This server negotiates 2025-06-18, where tools/list_changed is an
 * ordinary notification, and reload_mcp sends one. A client told the list
 * never changes may ignore it, so the flag this server configured is the one
 * it must answer with.
 */
beforeEach(function () {
    $this->capabilities = new ServerCapabilities(tools: true, toolsListChanged: true, resources: true, resourcesListChanged: false, prompts: true, promptsListChanged: false);
    $this->configuration = new Configuration(new Implementation('Test', '1.0.0'), $this->capabilities, protocolVersion: ProtocolVersion::V2025_06_18);
    $this->request = (new InitializeRequest('2025-06-18', new ClientCapabilities(), new Implementation('client', '1')))->withId(1);
    $this->answer = fn ($handler): InitializeResult => $handler->handle($this->request, new Session(new InMemorySessionStore()))->result;
});

it('pins why this exists: the SDK\'s own handler drops the flag', function () {
    expect(($this->answer)(new InitializeHandler($this->configuration))->capabilities->toolsListChanged)->toBeFalse();
});

it('answers initialize with the tool list change flag this server configured', function () {
    $result = ($this->answer)(new ListChangedInitialize(new InitializeHandler($this->configuration), $this->capabilities));

    expect($result->capabilities->toolsListChanged)->toBeTrue()
        ->and($result->capabilities->resourcesListChanged)->toBeFalse()
        ->and($result->capabilities->promptsListChanged)->toBeFalse();
});

it('leaves everything else of the SDK\'s answer alone, version negotiation included', function () {
    $sdk = ($this->answer)(new InitializeHandler($this->configuration));
    $ours = ($this->answer)(new ListChangedInitialize(new InitializeHandler($this->configuration), $this->capabilities));

    expect($ours->protocolVersion)->toBe($sdk->protocolVersion)
        ->and($ours->serverInfo)->toEqual($sdk->serverInfo)
        ->and($ours->instructions)->toBe($sdk->instructions);
});

it('answers initialize requests only', function () {
    expect((new ListChangedInitialize(new InitializeHandler($this->configuration), $this->capabilities))->supports($this->request))->toBeTrue();
});
