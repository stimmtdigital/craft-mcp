<?php

declare(strict_types=1);

use stimmt\craft\Mcp\insight\QueryDuplicates;

/** A db panel message as yii\log\Logger records it: [message, level, category, timestamp, traces, memory]. */
$message = static fn (string $sql, int $level = QueryDuplicates::PROFILE_BEGIN): array => [$sql, $level, 'yii\db\Command::query', 1.0, [], 0];

describe('QueryDuplicates::normalize()', function () {
    it('replaces the bound values Yii wrote into the SQL', function (string $raw, string $normalized) {
        expect(QueryDuplicates::normalize($raw))->toBe($normalized);
    })->with([
        'a string' => ["SELECT * FROM `users` WHERE `email`='ann@example.com'", 'SELECT * FROM `users` WHERE `email`=?'],
        'an escaped quote' => ["SELECT 1 FROM t WHERE a='it''s' AND b='x\\'y'", 'SELECT ? FROM t WHERE a=? AND b=?'],
        'integers and decimals' => ['SELECT * FROM t WHERE id = 42 AND price > 9.99 AND n = -3', 'SELECT * FROM t WHERE id = ? AND price > ? AND n = ?'],
        'a hex literal' => ['SELECT * FROM t WHERE uid = 0xDEADBEEF', 'SELECT * FROM t WHERE uid = ?'],
        'an IN list of any length' => ['SELECT * FROM t WHERE id IN (1, 2, 3, 4)', 'SELECT * FROM t WHERE id IN (?, ...)'],
        'runs of whitespace' => ["SELECT *\n\t  FROM t", 'SELECT * FROM t'],
    ]);

    it('leaves digits that belong to an identifier alone', function () {
        expect(QueryDuplicates::normalize('SELECT t1.id FROM `elements_sites2` t1 WHERE t1.x = 5'))
            ->toBe('SELECT t1.id FROM `elements_sites2` t1 WHERE t1.x = ?');
    });

    // The whole point of normalizing before output: a hash or token bound
    // into a query must never reach the tool result.
    it('lets no bound secret through', function () {
        $sql = "UPDATE `sessions` SET `token`='Zx9-secret-token' WHERE `userId`=7";

        expect(QueryDuplicates::normalize($sql))->not->toContain('secret')
            ->and(QueryDuplicates::normalize($sql))->not->toContain('7');
    });

    it('truncates a long statement', function () {
        $normalized = QueryDuplicates::normalize('SELECT ' . str_repeat('col, ', 200) . 'x FROM t');

        expect(mb_strlen($normalized))->toBe(QueryDuplicates::SQL_LENGTH)
            ->and($normalized)->toEndWith('…');
    });
});

describe('QueryDuplicates::group()', function () use ($message) {
    it('groups the same statement with different values, most repeated first', function () use ($message) {
        // The less repeated statement comes first, so the order is the sort's doing.
        $messages = [
            $message("SELECT * FROM users WHERE name = 'a'"),
            $message("SELECT * FROM users WHERE name = 'b'"),
            $message('SELECT * FROM entries WHERE id = 1'),
            $message('SELECT * FROM entries WHERE id = 1', 0x60),
            $message('SELECT * FROM entries WHERE id = 2'),
            $message('SELECT * FROM entries WHERE id = 3'),
            $message('SELECT * FROM sites'),
        ];

        expect(QueryDuplicates::group($messages))->toBe([
            ['sql' => 'SELECT * FROM entries WHERE id = ?', 'count' => 3],
            ['sql' => 'SELECT * FROM users WHERE name = ?', 'count' => 2],
        ]);
    });

    // Each query is logged twice, as a begin and an end entry; counting both
    // would call every query a duplicate of itself.
    it('counts a query once, not once per begin and end entry', function () use ($message) {
        $messages = [$message('SELECT 1 FROM t'), $message('SELECT 1 FROM t', 0x60)];

        expect(QueryDuplicates::group($messages))->toBe([]);
    });

    it('caps the number of groups', function () use ($message) {
        $messages = [];
        foreach (range(1, 8) as $table) {
            $messages[] = $message("SELECT * FROM t{$table}");
            $messages[] = $message("SELECT * FROM t{$table}");
        }

        expect(QueryDuplicates::group($messages, 3))->toHaveCount(3);
    });

    it('ignores entries that are not a logged statement', function () use ($message) {
        expect(QueryDuplicates::group(['junk', [null, 0x50], $message('SELECT 1')]))->toBe([]);
    });
});
