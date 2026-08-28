<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Exception\ToolCallException;

/**
 * Basic read-only guard for user-supplied SQL.
 *
 * WARNING: keyword-based, not a real sandbox. It can be bypassed (comments,
 * multi-statement queries if the PDO driver allows them). It exists to stop
 * obvious writes in a development tool, not to make arbitrary SQL safe.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SqlReadGuard {
    /**
     * Write/DDL keywords rejected anywhere in the query.
     */
    private const array BLOCKED_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE',
        'ALTER', 'CREATE', 'GRANT', 'REVOKE', 'INTO OUTFILE',
    ];

    /**
     * Assert the query is a read-only SELECT and return it trimmed.
     *
     * Keywords are matched on word boundaries, and against the statement's
     * skeleton rather than its raw text, so neither a column name containing a
     * keyword (`dateUpdated`) nor one quoted as data (`LIKE '%update%'`) is
     * mistaken for a write.
     *
     * @throws ToolCallException if the query is not a bare SELECT or contains a blocked keyword
     */
    public static function assertSelectOnly(string $sql): string {
        $trimmed = trim($sql);

        if (!preg_match('/^SELECT\b/i', $trimmed)) {
            throw new ToolCallException('Only SELECT queries are allowed for safety.');
        }

        $skeleton = SqlSkeleton::of($trimmed);
        $blocked = array_find(
            self::BLOCKED_KEYWORDS,
            static fn (string $keyword): bool => $skeleton->has($keyword),
        );

        if ($blocked !== null) {
            throw new ToolCallException(
                "Query contains blocked keyword: {$blocked}. Only SELECT statements are allowed; "
                . 'a blocked word inside a string literal is fine, but not as SQL.',
            );
        }

        return $trimmed;
    }
}
