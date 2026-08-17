<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\PromptGetException;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ResourceReadException;
use Mcp\Exception\ToolCallException;
use Throwable;

/**
 * Turns whatever escapes a call into the one exception type the matching SDK
 * handler will render.
 *
 * WHY, given every tool body already wraps itself: the wrappers only cover the
 * body. Three things happen outside one and were flattened into the SDK's fixed
 * string `Error while executing tool`, with the real message discarded:
 *
 * - argument preparation, which throws a RegistryException carrying both a
 *   useful message ("Missing required argument `id` for EntryTools::getEntry")
 *   and a JSON-RPC code, for a cast the schema admits but PHP cannot make;
 * - result formatting, which throws a JsonException on output that will not
 *   encode, very reachable for tools returning arbitrary database or PHP values;
 * - anything the output layer itself gets wrong, which is not hypothetical:
 *   this branch shipped a fatal there that broke nineteen tools.
 *
 * Outermost is the whole point. An inner boundary covers none of them.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class ErrorBoundary implements ReferenceHandlerInterface {
    use ExceptionFormatterTrait;

    public function __construct(private ReferenceHandlerInterface $handler) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function handle(ElementReference $reference, array $arguments): mixed {
        try {
            return $this->handler->handle($reference, $arguments);
        } catch (ToolCallException|PromptGetException|ResourceReadException $expected) {
            // Already the type its handler renders, and already carrying a
            // message someone chose. Re-wrapping would only bury it.
            throw $expected;
        } catch (Throwable $unexpected) {
            throw $this->convert($reference, $unexpected);
        }
    }

    private function convert(ElementReference $reference, Throwable $exception): Throwable {
        $message = $this->readable($exception);

        return match (true) {
            $reference instanceof ToolReference => new ToolCallException($message, (int) $exception->getCode(), $exception),
            $reference instanceof PromptReference => new PromptGetException($message, (int) $exception->getCode(), $exception),
            default => new ResourceReadException($message, (int) $exception->getCode(), $exception),
        };
    }

    /**
     * A caller mistake keeps its own words.
     *
     * RegistryException already says which argument was wrong and why ("Missing
     * required argument `id` for EntryTools::getEntry"), which is precisely
     * what the agent needs to fix its next call. Prefixing that with our class
     * and line number would bury the useful half under a vendor path, for a
     * mistake that is not ours. Everything else gets the location, because for
     * those it is the first thing anyone debugging will want.
     */
    private function readable(Throwable $exception): string {
        return $exception instanceof RegistryException
            ? $exception->getMessage()
            : self::formatErrorMessage($exception);
    }
}
