<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\logging;

use Throwable;

/**
 * Turns a PSR-3 context array into the single JSON line FileLogger appends.
 *
 * WHY this exists: json_encode() serializes an object's PUBLIC properties, and a
 * Throwable has none, so the idiomatic `['exception' => $e]` context that both
 * this plugin and the MCP SDK pass encoded to a bare `{}`. Every error the
 * server ever logged was therefore undiagnosable. Throwables are converted to a
 * readable structure first, at any depth in the context and along the whole
 * `previous` chain, because the root cause is usually the innermost link.
 *
 * Everything is bounded. The log format is one entry per line, so a trace from
 * inside a deep recursion could otherwise write megabytes for a single error;
 * the depth cap doubles as the cycle guard for self-referencing arrays, which
 * would otherwise recurse forever before json_encode ever saw them.
 *
 * Encoding never throws. A logger that fails while reporting a failure hides
 * the very thing it was called to record, so unsupported values (resources,
 * object cycles, invalid UTF-8) degrade to a marker or a substitution instead.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Context {
    /**
     * Array nesting kept before a value is replaced by a marker. Deep enough
     * for a realistic tool payload, shallow enough to terminate on a cycle.
     */
    private const int MAX_DEPTH = 6;

    /**
     * Stack frames kept per throwable. Enough to see who called what, far
     * short of the hundreds a recursive failure produces.
     */
    private const int MAX_FRAMES = 15;

    /**
     * Links of the `previous` chain expanded, head included.
     */
    private const int MAX_CHAIN = 5;

    private const string DEPTH_MARKER = '[truncated: max depth]';

    private const string CHAIN_MARKER = '[truncated: previous chain]';

    private const string UNENCODABLE = '{"context":"[unencodable]"}';

    /**
     * @param array<string, mixed> $context
     */
    public function encode(array $context): string {
        $json = json_encode(
            $this->normalize($context, 0),
            JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $json === false ? self::UNENCODABLE : $json;
    }

    private function normalize(mixed $value, int $depth): mixed {
        if ($value instanceof Throwable) {
            return $this->describe($value, 1);
        }

        if (!is_array($value)) {
            return $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            return self::DEPTH_MARKER;
        }

        return array_map(fn (mixed $item): mixed => $this->normalize($item, $depth + 1), $value);
    }

    /**
     * @param int $link Position in the previous chain, 1 for the thrown one.
     * @return array<string, mixed>
     */
    private function describe(Throwable $exception, int $link): array {
        $described = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->frames($exception),
        ];

        $previous = $exception->getPrevious();
        if ($previous === null) {
            return $described;
        }

        $described['previous'] = $link >= self::MAX_CHAIN
            ? self::CHAIN_MARKER
            : $this->describe($previous, $link + 1);

        return $described;
    }

    /**
     * The trace as one string per frame. getTraceAsString() would be shorter
     * but embeds newlines, which the one-line log format cannot carry, and it
     * renders arguments; those are dropped here because a tool call's
     * arguments can be both enormous and sensitive.
     *
     * @return string[]
     */
    private function frames(Throwable $exception): array {
        $trace = $exception->getTrace();
        $frames = array_map($this->frame(...), array_slice($trace, 0, self::MAX_FRAMES));

        $dropped = count($trace) - count($frames);
        if ($dropped > 0) {
            $frames[] = "... {$dropped} more frames";
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function frame(array $frame): string {
        $file = is_string($frame['file'] ?? null) ? $frame['file'] : '[internal]';
        $line = is_int($frame['line'] ?? null) ? $frame['line'] : 0;
        $class = is_string($frame['class'] ?? null) ? $frame['class'] : '';
        $type = is_string($frame['type'] ?? null) ? $frame['type'] : '';
        $function = is_string($frame['function'] ?? null) ? $frame['function'] : '{closure}';

        return "{$file}:{$line} {$class}{$type}{$function}()";
    }
}
