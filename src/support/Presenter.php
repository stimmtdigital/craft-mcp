<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use stimmt\craft\Mcp\enums\ResponseFormat;

/**
 * Decides what a tool call actually puts on the wire.
 *
 * Left to itself, the SDK sends every array payload twice: CallToolHandler
 * calls extractStructuredContent() (which returns an array verbatim) and
 * formatResult() (which JSON-encodes the same array), and CallToolResult
 * serializes both `content` and `structuredContent`. No tool here declares an
 * outputSchema, so the second copy buys a client nothing and costs it the
 * whole payload again in tokens. That handler skips both calls when the
 * reference handler already returned a CallToolResult, so building one here
 * is the one interception point that fixes it for every tool at once, without
 * a line of change in any tool.
 *
 * It also serves the text convention: a tool opts into human-readable output
 * purely by declaring `output: ResponseFormat` in its signature, which puts
 * the parameter in its JSON schema. This class does the rendering centrally
 * and never guesses for a tool that did not declare it.
 *
 * And it is where a domain failure becomes a protocol failure: a tool says so
 * in its own payload through Response::failure(), and this class is the single
 * place that turns that into isError on the wire, so no tool has to know what
 * a CallToolResult is.
 *
 * The same reference handler serves prompts and resources, so anything that is
 * not a tool passes straight through.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Presenter implements ReferenceHandlerInterface {
    /**
     * The parameter a tool declares to offer a text view. One name, so the
     * convention is discoverable from any tool's schema.
     */
    public const string OUTPUT_PARAM = 'output';

    /**
     * The one description of the convention, so every opted-in tool advertises
     * it identically in its schema.
     */
    public const string OUTPUT_DESCRIPTION = 'Response format: "structured" (default) returns the JSON payload, "text" returns the same data laid out for a person to read.';

    public function __construct(
        private ReferenceHandlerInterface $handler,
        private Renderer $renderer,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function handle(ElementReference $reference, array $arguments): mixed {
        $result = $this->handler->handle($reference, $arguments);

        if (!$reference instanceof ToolReference) {
            return $result;
        }

        if ($result instanceof CallToolResult) {
            return $result;
        }

        // A payload that states its own failure IS a failed call, and isError
        // is the only place a client reads that from. Without it a validation
        // refusal arrives as a successful call whose JSON happens to say
        // otherwise, and the model has no reason to self-correct.
        if (Response::isFailure($result)) {
            return new CallToolResult($this->content($reference, $result, $arguments), isError: true);
        }

        return new CallToolResult(
            $this->content($reference, $result, $arguments),
            structuredContent: $this->structured($reference, $result),
        );
    }

    /**
     * The content of a tool result: rendered for text, formatted otherwise.
     *
     * Both outcomes come through here, so a tool that opted into the text view
     * gets its failures as text too. formatResult() is the SDK's own content
     * formatting (Content objects pass through, arrays become one JSON
     * TextContent), so tools that already return TextContent keep their exact
     * output.
     *
     * @param array<string, mixed> $arguments
     * @return Content[]
     */
    private function content(ToolReference $reference, mixed $result, array $arguments): array {
        if (is_array($result) && $this->wantsText($reference, $arguments)) {
            return [new TextContent($this->renderer->render($result))];
        }

        return $reference->formatResult($result);
    }

    /**
     * The structured copy, which only a tool that declared an output schema
     * gets.
     *
     * The duplicate is dropped while no tool declares one, because the client
     * cannot be obliged to read it and it costs the whole payload again in
     * tokens. A tool that DOES declare one is contractually owed it, and
     * dropping it then would mean advertising a schema and never honouring it.
     * That holds for the text view as well: text is a presentation choice, the
     * schema contract is not. It never applies to a failure, which by
     * definition does not conform to the declared shape; CallToolResult::error()
     * makes the same choice.
     *
     * @return array<string, mixed>|null
     */
    private function structured(ToolReference $reference, mixed $result): ?array {
        return $reference->tool->outputSchema === null
            ? null
            : $reference->extractStructuredContent($result);
    }

    /**
     * True only when the tool advertises the output parameter as a
     * ResponseFormat and this call asked for text.
     *
     * @param array<string, mixed> $arguments
     */
    private function wantsText(ToolReference $reference, array $arguments): bool {
        if (!$this->declaresOutput($reference)) {
            return false;
        }

        $requested = $arguments[self::OUTPUT_PARAM] ?? null;
        if ($requested instanceof ResponseFormat) {
            return $requested === ResponseFormat::TEXT;
        }

        return is_string($requested) && ResponseFormat::tryFrom($requested) === ResponseFormat::TEXT;
    }

    /**
     * The schema, not the argument bag, is what makes the opt-in explicit: a
     * tool with its own unrelated `output` parameter (tinker's dump mode) is
     * not opted in, because its schema advertises different values.
     */
    private function declaresOutput(ToolReference $reference): bool {
        $properties = $this->fragment($reference->tool->inputSchema['properties'] ?? null);
        $declared = $this->fragment($properties[self::OUTPUT_PARAM] ?? null)['enum'] ?? null;

        if (!is_array($declared)) {
            return false;
        }

        return array_diff(array_column(ResponseFormat::cases(), 'value'), $declared) === [];
    }

    /**
     * A JSON schema fragment as an array, whichever shape it arrives in.
     *
     * A tool with no parameters does not carry an empty properties array: the
     * SDK rewrites it to a stdClass so the schema serializes as `{}` instead
     * of `[]` (Mcp\Schema\Tool::normalizeSchemaProperties(), and the same
     * cleanup in Capability\Discovery\SchemaGenerator::buildSchemaFromParameters()).
     * Subscripting that object was a fatal Error, so every parameterless tool
     * (reload_mcp among them) died here before its result reached the client.
     * An externally registered tool may equally hand in a decoded JSON schema
     * that is objects throughout, so both shapes are read the same way.
     *
     * @return array<string, mixed>
     */
    private function fragment(mixed $value): array {
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return is_array($value) ? $value : [];
    }
}
