<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\Response;

describe('Response::success()', function () {
    it('returns success true with empty data', function () {
        $result = Response::success();

        expect($result)->toBeSuccessResponse()
            ->and($result)->toBe(['success' => true]);
    });

    it('returns success true with additional data', function () {
        $result = Response::success(['count' => 5, 'items' => ['a', 'b']]);

        expect($result)->toBeSuccessResponse()
            ->and($result)->toBe([
                'success' => true,
                'count' => 5,
                'items' => ['a', 'b'],
            ]);
    });

    it('merges data using spread operator', function () {
        $result = Response::success(['message' => 'Done', 'id' => 123]);

        expect($result)
            ->toHaveKey('success', true)
            ->toHaveKey('message', 'Done')
            ->toHaveKey('id', 123);
    });
});

describe('Response::found()', function () {
    it('returns found response with data', function () {
        $data = ['id' => 1, 'title' => 'Test'];
        $result = Response::found('entry', $data);

        expect($result)->toBeFoundResponse()
            ->and($result)->toBe([
                'found' => true,
                'entry' => $data,
            ]);
    });

    it('returns found response with different key', function () {
        $result = Response::found('user', ['name' => 'John']);

        expect($result)
            ->toHaveKey('found', true)
            ->toHaveKey('user', ['name' => 'John']);
    });

    it('handles null data', function () {
        $result = Response::found('item', null);

        expect($result)->toBeFoundResponse()
            ->and($result['item'])->toBeNull();
    });
});

describe('Response::list()', function () {
    it('returns list with count and items', function () {
        $items = [['id' => 1], ['id' => 2], ['id' => 3]];
        $result = Response::list('entries', $items);

        expect($result)
            ->toHaveKey('count', 3)
            ->toHaveKey('entries', $items);
    });

    it('returns list with meta data', function () {
        $items = [['id' => 1]];
        $result = Response::list('assets', $items, ['volume' => 'images']);

        expect($result)
            ->toHaveKey('count', 1)
            ->toHaveKey('volume', 'images')
            ->toHaveKey('assets', $items);
    });

    it('handles empty list', function () {
        $result = Response::list('users', []);

        expect($result)
            ->toHaveKey('count', 0)
            ->toHaveKey('users', []);
    });
});

describe('Response::paginated()', function () {
    it('returns paginated response with all fields', function () {
        $items = [['id' => 1], ['id' => 2]];
        $result = Response::paginated('entries', $items, 100, 10, 20);

        expect($result)
            ->toHaveKey('count', 2)
            ->toHaveKey('total', 100)
            ->toHaveKey('limit', 10)
            ->toHaveKey('offset', 20)
            ->toHaveKey('entries', $items);
    });

    it('handles first page', function () {
        $items = [['id' => 1]];
        $result = Response::paginated('items', $items, 50, 10, 0);

        expect($result)
            ->toHaveKey('count', 1)
            ->toHaveKey('total', 50)
            ->toHaveKey('limit', 10)
            ->toHaveKey('offset', 0);
    });

    // The question a page is asked: an agent handed exactly `limit` rows
    // cannot tell a full page from a complete set, and counting to the answer
    // itself is where the off-by-one lives.
    it('answers whether more rows are behind the page', function (int $total, int $limit, int $offset, int $rows, bool $hasMore) {
        $items = array_fill(0, $rows, ['id' => 1]);

        expect(Response::paginated('entries', $items, $total, $limit, $offset)['hasMore'])->toBe($hasMore);
    })->with([
        'a full page with more behind it' => [100, 10, 0, 10, true],
        'the last page, exactly filled' => [20, 10, 10, 10, false],
        'a partial last page' => [15, 10, 10, 5, false],
        'a total that fits in one page' => [3, 10, 0, 3, false],
        'nothing at all' => [0, 10, 0, 0, false],
        'an offset past the end' => [50, 10, 1000, 0, false],
    ]);

    it('handles empty page', function () {
        $result = Response::paginated('results', [], 0, 10, 0);

        expect($result)
            ->toHaveKey('count', 0)
            ->toHaveKey('total', 0);
    });
});

describe('Response::capped()', function () {
    // No offset, because the tools that cap without paging read something that
    // is not an addressable result set. Echoing a zero would advertise paging
    // that does not exist.
    it('answers the same envelope minus the offset it does not have', function () {
        $result = Response::capped('entries', [['id' => 1]], 50, 200);

        expect($result)->toBe([
            'count' => 1,
            'total' => 200,
            'limit' => 50,
            'hasMore' => true,
            'entries' => [['id' => 1]],
        ]);
    });

    // "count reached the limit" is not the same fact as "more rows exist", and
    // a tool that cannot count the matches says so rather than guessing, with
    // count and limit right there for a caller that wants the inference.
    it('says it cannot tell rather than inferring from a full page', function () {
        $full = Response::capped('entries', [['id' => 1], ['id' => 2]], 2);

        expect($full['total'])->toBeNull()
            ->and($full['hasMore'])->toBeNull()
            ->and($full['limit'])->toBe(2);
    });

    it('counts against a total when the tool has one', function () {
        $result = Response::capped('jobs', [['id' => 1]], 5, 1);

        expect($result['hasMore'])->toBeFalse();
    });

    // run_query only appends its LIMIT when the statement does not carry one,
    // so naming this parameter as the cap in force would be a second lie one
    // level down from the missing total.
    it('reports no cap for a tool whose cap the caller can override', function () {
        $result = Response::capped('rows', [['id' => 1]], null, meta: ['columns' => ['id']]);

        expect($result)->toBe([
            'count' => 1,
            'total' => null,
            'limit' => null,
            'hasMore' => null,
            'columns' => ['id'],
            'rows' => [['id' => 1]],
        ]);
    });
});
