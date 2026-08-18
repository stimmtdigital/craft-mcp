<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
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

        if (is_array($result) && $this->wantsText($reference, $arguments)) {
            return new CallToolResult([new TextContent($this->renderer->render($result))]);
        }

        // formatResult() is the SDK's own content formatting (Content objects
        // pass through, arrays become one JSON TextContent), so tools that
        // already return TextContent keep their exact output. Only the
        // structuredContent duplicate is dropped, and only while no tool
        // declares an output schema: a tool that declares one is contractually
        // owed the structured copy, and dropping it unconditionally would mean
        // advertising a schema and then never honouring it. No tool declares
        // one today, so this branch is dormant rather than speculative; it
        // exists because the day one does, the failure would be silent.
        $structured = $reference->tool->outputSchema === null
            ? null
            : $reference->extractStructuredContent($result);

        return new CallToolResult($reference->formatResult($result), structuredContent: $structured);
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
