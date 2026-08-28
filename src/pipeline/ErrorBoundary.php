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
use stimmt\craft\Mcp\elements\InvalidInput;
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
     *
     * InvalidInput is the same kind of thing one layer in: the elements module
     * cannot raise ToolCallException (it is kept free of the SDK), so it names
     * a bad date or an unknown field handle with that type instead, and the
     * sentence it carries is written for the agent word for word. Without this
     * the two halves of one guard answered differently, an unknown SECTION in
     * clean prose and an unknown FIELD as "InvalidArgumentException: ...
     * (Filters.php:119)", for mistakes of exactly the same kind.
     *
     * Vendor exceptions keep their detail, which can include a SQL statement, a
     * DSN or an absolute path. That is decided rather than overlooked. Every
     * caller is already authenticated, by a bearer token or by local stdio
     * access, and get_database_schema hands the same structural facts to the
     * readonly scope on request, so the message discloses nothing a caller
     * could not simply ask for. Redacting it would cost the one thing these
     * messages exist for, which is telling whoever reads the transcript what
     * actually broke.
     */
    private function readable(Throwable $exception): string {
        return $exception instanceof RegistryException || $exception instanceof InvalidInput
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
