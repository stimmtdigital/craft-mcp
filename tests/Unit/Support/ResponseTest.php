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

describe('Response::error()', function () {
    it('returns error response with message', function () {
        $result = Response::error('Something went wrong');

        expect($result)->toBeErrorResponse()
            ->and($result['error'])->toBe('Something went wrong');
    });

    it('returns error response with context', function () {
        $result = Response::error('Validation failed', ['field' => 'email', 'code' => 422]);

        expect($result)->toBeErrorResponse()
            ->and($result)->toBe([
                'success' => false,
                'error' => 'Validation failed',
                'field' => 'email',
                'code' => 422,
            ]);
    });
});

describe('Response::notFound()', function () {
    it('returns not found without identifier', function () {
        $result = Response::notFound('Entry');

        expect($result)->toBeNotFoundResponse()
            ->and($result['error'])->toBe('Entry not found');
    });

    it('returns not found with string identifier', function () {
        $result = Response::notFound('Entry', 'my-slug');

        expect($result)->toBeNotFoundResponse()
            ->and($result['error'])->toBe("Entry 'my-slug' not found");
    });

    it('returns not found with integer identifier', function () {
        $result = Response::notFound('User', 42);

        expect($result)->toBeNotFoundResponse()
            ->and($result['error'])->toBe("User '42' not found");
    });

    it('returns not found with null identifier', function () {
        $result = Response::notFound('Asset', null);

        expect($result)->toBeNotFoundResponse()
            ->and($result['error'])->toBe('Asset not found');
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

    it('handles empty page', function () {
        $result = Response::paginated('results', [], 0, 10, 0);

        expect($result)
            ->toHaveKey('count', 0)
            ->toHaveKey('total', 0);
    });
});
