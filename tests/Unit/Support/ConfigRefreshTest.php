<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/RealCraft.php';

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Prompt;
use Mcp\Schema\Tool;
use stimmt\craft\Mcp\support\ConfigRefresh;

/**
 * The freshness probe only notifies when handed a request context, and it used
 * to be threaded by hand: 4 of 62 call sites passed one, so it ran on every
 * call and could reach nobody. The guarantee now belongs to this decorator,
 * which sees the session and request the SDK injects on every call, so it holds
 * for all tools rather than the two that remembered.
 *
 * Helpers are closures rather than functions: Pest shares global function names
 * across every test file, and `toolReference` is already taken.
 */
beforeEach(function () {
    $this->recorder = function (array &$seen): ReferenceHandlerInterface {
        return new class ($seen) implements ReferenceHandlerInterface {
            /** @param list<string> $seen */
            public function __construct(private array &$seen) {
            }

            public function handle(ElementReference $reference, array $arguments): mixed {
                $this->seen[] = $reference::class;

                return 'delegated';
            }
        };
    };
});

it('delegates a tool call and returns the inner result untouched', function () {
    $seen = [];
    $reference = new ToolReference(
        new Tool(name: 'demo', title: null, inputSchema: ['type' => 'object'], description: null, annotations: null),
        static fn (): array => [],
    );

    $result = (new ConfigRefresh(($this->recorder)($seen)))->handle($reference, []);

    expect($result)->toBe('delegated')
        ->and($seen)->toBe([ToolReference::class]);
});

it('passes a prompt straight through, since a prompt cannot change project config', function () {
    $seen = [];
    $reference = new PromptReference(
        new Prompt(name: 'demo', description: null, arguments: null),
        static fn (): array => [],
    );

    $result = (new ConfigRefresh(($this->recorder)($seen)))->handle($reference, []);

    expect($result)->toBe('delegated')
        ->and($seen)->toBe([PromptReference::class]);
});
