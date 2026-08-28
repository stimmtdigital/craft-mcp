<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\SqlSkeleton;

describe('SqlSkeleton', function () {
    it('finds a keyword that is really part of the statement', function (string $sql) {
        expect(SqlSkeleton::of($sql)->has('LIMIT'))->toBeTrue();
    })->with([
        'plain' => 'SELECT id FROM entries LIMIT 5',
        'lowercase' => 'select id from entries limit 5',
        'after a blanked comment' => 'SELECT id FROM entries /* note */ LIMIT 5',
        'alongside a literal' => "SELECT id FROM entries WHERE title = 'limit' LIMIT 5",
    ]);

    // The silent one. run_query read this as "the caller bounded it already"
    // and appended nothing, so a request for two rows returned the whole table.
    it('does not find a keyword that is only data', function (string $sql) {
        expect(SqlSkeleton::of($sql)->has('LIMIT'))->toBeFalse();
    })->with([
        'single-quoted' => "SELECT id FROM elements WHERE 'limit' = 'limit'",
        'inside a like' => "SELECT id FROM entries WHERE title LIKE '%limit%'",
        'doubled quote escape' => "SELECT id FROM entries WHERE title = 'it''s a limit'",
        'backslash escape' => "SELECT id FROM entries WHERE title = 'a \\' limit'",
        'line comment' => 'SELECT id FROM entries -- LIMIT 5',
        'block comment' => 'SELECT id FROM entries /* LIMIT 5 */',
        'backtick identifier' => 'SELECT `limit` FROM entries',
    ]);

    // Why the literal is blanked and not deleted: deleting closes the gap, and
    // the fragments on either side become a keyword nobody wrote.
    it('does not let a removed literal join its neighbours into a keyword', function () {
        expect(SqlSkeleton::of("SELECT 1 FROM t WHERE a = LIM'x'IT")->has('LIMIT'))->toBeFalse();
    });

    it('reads a multi-word keyword across any run of whitespace', function (string $sql) {
        expect(SqlSkeleton::of($sql)->has('INTO OUTFILE'))->toBeTrue();
    })->with([
        'one space' => 'SELECT * FROM entries INTO OUTFILE "/tmp/x"',
        'several spaces' => 'SELECT * FROM entries INTO   OUTFILE "/tmp/x"',
        'a newline' => "SELECT * FROM entries INTO\nOUTFILE \"/tmp/x\"",
        'a tab' => "SELECT * FROM entries INTO\tOUTFILE \"/tmp/x\"",
    ]);

    it('does not match a keyword that is only a substring of an identifier', function () {
        $skeleton = SqlSkeleton::of('SELECT dateUpdated, dateCreated FROM elements');

        expect($skeleton->has('UPDATE'))->toBeFalse()
            ->and($skeleton->has('CREATE'))->toBeFalse();
    });

    it('answers no for a keyword that is not a word', function (string $keyword) {
        expect(SqlSkeleton::of('SELECT 1')->has($keyword))->toBeFalse();
    })->with([[''], [' '], ["\n"]]);

    // Fails closed rather than guessing: the guard is safest assuming every
    // keyword is present and the LIMIT check is safest assuming none is, so no
    // single fallback serves both callers, and a statement nobody could analyse
    // is not one to run. Reached here with an unterminated block comment, whose
    // lazy match backtracks over the rest of the input.
    it('refuses a statement it cannot reduce instead of assuming', function () {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100');

        try {
            expect(fn () => SqlSkeleton::of('SELECT 1 /*' . str_repeat('a', 5000)))
                ->toThrow(ToolCallException::class, 'Could not analyse this SQL safely');
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    });

    // The clause has to land as code. Appended inline it was swallowed by a
    // trailing comment, so `-- x` turned `limit: 1` into the whole table: the
    // same silent wrong answer the skeleton exists to prevent, through the
    // other door.
    it('puts an appended limit where the database will see it', function (string $sql) {
        expect(SqlSkeleton::of($sql)->bounded(1))->toEndWith("\nLIMIT 1");
    })->with([
        'trailing line comment' => 'SELECT id FROM sites -- x',
        'trailing hash comment' => 'SELECT id FROM sites # trailing',
        'a commented limit is not a limit' => 'SELECT id FROM elements -- LIMIT 5',
        'no comment at all' => 'SELECT id FROM sites',
        'trailing semicolon' => 'SELECT id FROM sites;',
    ]);

    it('leaves a statement the caller already bounded exactly as it is', function (string $sql) {
        expect(SqlSkeleton::of($sql)->bounded(99))->toBe($sql);
    })->with([
        'plain' => 'SELECT id FROM sites LIMIT 3',
        'with offset' => 'SELECT id FROM sites LIMIT 3 OFFSET 2',
        'lowercase' => 'select id from sites limit 3',
    ]);
});
