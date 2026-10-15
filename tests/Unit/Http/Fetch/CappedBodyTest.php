<?php

declare(strict_types=1);

use stimmt\craft\Mcp\http\fetch\CappedBody;

/**
 * The transport keeps what arrives in a CappedBody and stops reading the
 * moment it is exceeded, so a file far over the limit is never held whole.
 */
describe('CappedBody', function () {
    it('keeps every chunk while the body fits', function () {
        $body = new CappedBody(10);
        $body->append('abcd');
        $body->append('efghij');

        expect($body->bytes())->toBe('abcdefghij')
            ->and($body->exceeded())->toBeFalse();
    });

    it('keeps one byte past the cap and no more', function () {
        $body = new CappedBody(10);
        $body->append('abcdefgh');
        $body->append(str_repeat('x', 1000));

        expect($body->bytes())->toHaveLength(11)
            ->and($body->exceeded())->toBeTrue();
    });
});
