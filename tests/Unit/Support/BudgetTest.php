<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\Budget;

/**
 * Craft's MCP server has no hook around a tool call, so the response cap v1
 * enforced once in its pipeline is now each list-shaped tool's own call.
 * The size measured is the JSON the client would receive.
 */
describe('Budget::assert()', function () {
    it('hands a response under the limit back unchanged', function () {
        $response = ['rows' => [['id' => 1, 'title' => 'Héllo / world']]];

        expect(Budget::assert($response, 1024))->toBe($response);
    });

    it('lets a response of exactly the limit through', function () {
        $response = ['a' => 'xxxxx'];
        $size = strlen((string) json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        expect(Budget::assert($response, $size))->toBe($response);
    });

    it('refuses a response over the limit, saying how large it was and how to ask for less', function () {
        $response = ['rows' => str_repeat('x', 2000)];

        expect(fn () => Budget::assert($response, 1024))
            ->toThrow(ToolCallException::class, "The response is 2011 bytes, over this install's limit of 1024 (maxResponseBytes). Ask for less");
    });

    // Slashes and non-ASCII text are what log lines and URLs are made of;
    // counting them escaped would refuse responses the client never sees at
    // that size.
    it('measures unescaped JSON', function () {
        $response = ['url' => 'https://example.com/é'];

        expect(Budget::assert($response, 32))->toBe($response)
            ->and(fn () => Budget::assert($response, 31))->toThrow(ToolCallException::class);
    });
});
