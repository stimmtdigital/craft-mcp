<?php

declare(strict_types=1);

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Tool;
use stimmt\craft\Mcp\elements\InvalidInput;
use stimmt\craft\Mcp\pipeline\ErrorBoundary;

/**
 * Which throwables reach an agent as written, and which arrive with a class
 * name and a file and line bolted on.
 *
 * The two halves of one guard used to disagree: an unknown SECTION came back
 * as "Section 'nope' not found. Use list_sections for available handles." and
 * an unknown FIELD as "InvalidArgumentException: Unknown field handle 'nope'
 * in filters (Filters.php:154)", for caller mistakes of exactly the same
 * kind. The frame is not dangerous, it is noise the caller cannot act on, and
 * it is inconsistent, which is worse: an agent cannot learn a shape it only
 * sometimes sees.
 */
$boundary = static function (Throwable $thrown): ErrorBoundary {
    $handler = new class ($thrown) implements ReferenceHandlerInterface {
        public function __construct(private Throwable $thrown) {
        }

        public function handle(ElementReference $reference, array $arguments): mixed {
            throw $this->thrown;
        }
    };

    return new ErrorBoundary($handler);
};

$toolReference = static fn (): ToolReference => new ToolReference(
    new Tool('list_entries', null, ['type' => 'object', 'properties' => new stdClass()], 'List entries', null),
    static fn (): array => [],
);

describe('a caller mistake', function () use ($boundary, $toolReference) {
    // InvalidInput is how the elements module names a caller mistake: it is
    // kept free of the MCP SDK, so it cannot raise ToolCallException itself.
    it('keeps the words the guard chose, with no frame appended', function () use ($boundary, $toolReference) {
        $message = "Invalid date ' ' in a date filter (createdAfter, createdBefore).";

        expect(fn () => $boundary(new InvalidInput($message))->handle($toolReference(), []))
            ->toThrow(ToolCallException::class, $message);
    });

    it('mentions neither the class nor the file it came from', function () use ($boundary, $toolReference) {
        try {
            $boundary(new InvalidInput("Unknown field handle 'nope' in filters"))->handle($toolReference(), []);
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toBe("Unknown field handle 'nope' in filters")
                ->and($e->getMessage())->not->toContain('InvalidInput')
                ->and($e->getMessage())->not->toContain('.php:');

            return;
        }

        throw new RuntimeException('Expected a ToolCallException carrying the guard\'s own words');
    });

    // The SDK's own version of the same idea, which already behaved this way.
    it('treats an argument-preparation failure the same', function () use ($boundary, $toolReference) {
        try {
            $boundary(new RegistryException('Missing required argument `id`'))->handle($toolReference(), []);
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toBe('Missing required argument `id`');

            return;
        }

        throw new RuntimeException('Expected a ToolCallException carrying the registry message');
    });
});

describe('a fault that is ours', function () use ($boundary, $toolReference) {
    // Craft throws the bare SPL type from well over a hundred places of its
    // own, and those are bugs to debug rather than sentences to read, so they
    // keep the location. That is the whole reason InvalidInput is a type of
    // its own rather than a catch on InvalidArgumentException.
    it('still carries the class and the location', function (Throwable $thrown) use ($boundary, $toolReference) {
        try {
            $boundary($thrown)->handle($toolReference(), []);
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain('boom')
                ->toContain($thrown::class)
                ->toContain('ErrorBoundaryTest.php:');

            return;
        }

        throw new RuntimeException('Expected a ToolCallException with a location');
    })->with([
        'unexpected' => [new RuntimeException('boom')],
        'craft\'s own invalid argument' => [new InvalidArgumentException('boom')],
    ]);
});
