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
     * Keywords are matched on word boundaries so column names that merely
     * contain a keyword as a substring (e.g. `dateCreated`, `dateUpdated`)
     * are not falsely rejected.
     *
     * @throws ToolCallException if the query is not a bare SELECT or contains a blocked keyword
     */
    public static function assertSelectOnly(string $sql): string {
        $trimmed = trim($sql);

        if (!preg_match('/^SELECT\b/i', $trimmed)) {
            throw new ToolCallException('Only SELECT queries are allowed for safety.');
        }

        foreach (self::BLOCKED_KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $trimmed)) {
                throw new ToolCallException("Query contains blocked keyword: {$keyword}");
            }
        }

        return $trimmed;
    }
}
