<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\pipeline;

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
    public function __construct(private ReferenceHandlerInterface $handler) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function handle(ElementReference $reference, array $arguments): mixed {
        try {
            return $this->handler->handle($reference, $arguments);
        } catch (ToolCallException|PromptGetException|ResourceReadException $expected) {
            // Already carrying a message someone chose, so it keeps its words.
            // Not necessarily in the type THIS surface renders, though: shared
            // support code (HandleResolver, Authorization) speaks from all
            // three, and each SDK handler keeps the message of its own type
            // only, discarding the rest behind "Error while reading resource".
            throw $this->retype($reference, $expected, $expected->getMessage());
        } catch (Throwable $unexpected) {
            throw $this->retype($reference, $unexpected, $this->readable($unexpected));
        }
    }

    private function retype(ElementReference $reference, Throwable $exception, string $message): Throwable {
        $rendered = match (true) {
            $reference instanceof ToolReference => new ToolCallException($message, (int) $exception->getCode(), $exception),
            $reference instanceof PromptReference => new PromptGetException($message, (int) $exception->getCode(), $exception),
            default => new ResourceReadException($message, (int) $exception->getCode(), $exception),
        };

        // Already the right type: hand back the original rather than an
        // identical copy wrapping it, so the stack trace stays the throw site's.
        return $exception instanceof $rendered ? $exception : $rendered;
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
            : $this->formatErrorMessage($exception);
    }

    /**
     * A one-line account of a throwable: what it was, what it said, and where.
     *
     * This was a trait, shared with three Safe*Execution classes that no longer
     * exist. A trait with one user is just methods living somewhere a reader
     * has to go looking for them.
     */
    private function formatErrorMessage(Throwable $e): string {
        return sprintf('%s: %s (%s)', $this->shortClass($e), $e->getMessage(), $this->shortLocation($e));
    }

    private function shortClass(Throwable $e): string {
        $class = $e::class;
        $pos = strrpos($class, '\\');

        return $pos !== false ? substr($class, $pos + 1) : $class;
    }

    private function shortLocation(Throwable $e): string {
        return basename($e->getFile()) . ':' . $e->getLine();
    }
}
