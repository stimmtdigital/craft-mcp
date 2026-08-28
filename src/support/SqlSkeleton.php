<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Exception\ToolCallException;

/**
 * A statement with everything that is data rather than structure blanked out,
 * so that looking for a keyword finds only keywords.
 *
 * Both readers of raw SQL were wrong in the same way without it, in opposite
 * directions. The read guard refused `WHERE title LIKE '%update%'` as though it
 * were a write, which is merely annoying. run_query asked whether the statement
 * already carried a LIMIT clause, saw the word inside `WHERE 'limit' = 'limit'`,
 * concluded it did, and appended nothing: a call asking for two rows returned
 * every row in the table. That one is silent, and an agent has no reason to
 * doubt it.
 *
 * Blanks rather than deletes, so that removing a literal cannot join the text
 * on either side of it into a keyword that was never written: `LIM'x'IT` reads
 * as two fragments, not as LIMIT. What gets executed is always the caller's own
 * text; this only ever decides what to make of it.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class SqlSkeleton {
    /**
     * String literals (with doubled and backslash escapes), quoted identifiers,
     * and comments to end of line or end of block.
     */
    private const string NON_CODE = <<<'REGEX'
        /'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"|`(?:[^`]|``)*`|--[^\n]*|\#[^\n]*|\/\*.*?\*\//s
        REGEX;

    private function __construct(
        private string $code,
    ) {
    }

    /**
     * @throws ToolCallException if the statement cannot be reduced
     */
    public static function of(string $sql): self {
        $code = preg_replace_callback(
            self::NON_CODE,
            static fn (array $match): string => str_repeat(' ', strlen($match[0])),
            $sql,
        );

        // Refused rather than guessed. The two callers want opposite fallbacks
        // (the guard is safest assuming every keyword is present, the LIMIT
        // check is safest assuming none is), so neither default is safe for
        // both, and a statement nobody could analyse is not one to run.
        if ($code === null) {
            throw new ToolCallException(
                'Could not analyse this SQL safely, so it was not run. Simplify the statement and try again.',
            );
        }

        return new self($code);
    }

    /**
     * Whether the keyword appears as code. Whitespace inside a multi-word
     * keyword matches any run of it, so `INTO  OUTFILE` is the same keyword as
     * `INTO OUTFILE` rather than a way around the list.
     */
    public function has(string $keyword): bool {
        $words = preg_split('/\s+/', trim($keyword)) ?: [];
        if ($words === [] || $words === ['']) {
            return false;
        }

        $pattern = implode('\s+', array_map(
            static fn (string $word): string => preg_quote($word, '/'),
            $words,
        ));

        return preg_match('/\b' . $pattern . '\b/i', $this->code) === 1;
    }
}
