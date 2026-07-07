<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\SqlReadGuard;

describe('SqlReadGuard::assertSelectOnly()', function () {
    it('returns the trimmed query for a plain SELECT', function () {
        expect(SqlReadGuard::assertSelectOnly('  SELECT id FROM entries  '))
            ->toBe('SELECT id FROM entries');
    });

    it('allows columns whose names contain a blocked keyword as a substring', function (string $sql) {
        expect(SqlReadGuard::assertSelectOnly($sql))->toBe(trim($sql));
    })->with([
        'dateCreated (CREATE)' => 'SELECT dateCreated FROM entries',
        'dateUpdated (UPDATE)' => 'SELECT dateUpdated, title FROM entries',
        'both' => 'SELECT dateCreated, dateUpdated FROM elements',
    ]);

    it('blocks a query that is not a SELECT', function (string $sql) {
        expect(fn () => SqlReadGuard::assertSelectOnly($sql))
            ->toThrow(ToolCallException::class);
    })->with([
        'update' => 'UPDATE entries SET title = "x"',
        'delete' => 'DELETE FROM entries',
        'insert' => 'INSERT INTO entries (title) VALUES ("x")',
        'empty' => '',
    ]);

    it('blocks a SELECT that contains a real write keyword as a token', function (string $sql) {
        expect(fn () => SqlReadGuard::assertSelectOnly($sql))
            ->toThrow(ToolCallException::class);
    })->with([
        'create table via subquery' => 'SELECT 1; CREATE TABLE t (id int)',
        'drop' => 'SELECT 1; DROP TABLE entries',
        'delete' => 'SELECT 1; DELETE FROM entries',
        'into outfile' => 'SELECT * FROM entries INTO OUTFILE "/tmp/x"',
        'grant' => 'SELECT 1; GRANT ALL ON *.* TO x',
    ]);
});
