<?php

declare(strict_types=1);

use stimmt\craft\Mcp\http\fetch\CurlTransport;
use stimmt\craft\Mcp\http\fetch\FetchRefused;

/**
 * The pin is what keeps a DNS answer that changes after SafeFetch vetted it
 * from choosing where the connection lands. A name under .invalid can never
 * resolve, so if curl got anywhere at all it went to the pinned address.
 */
describe('CurlTransport', function () {
    it('connects to the pinned address instead of resolving the host', function () {
        try {
            (new CurlTransport())('https://pinned.invalid/cat.png', 'pinned.invalid', '127.0.0.1', 1024);
            $message = 'no failure';
        } catch (FetchRefused $e) {
            $message = $e->getMessage();
        }

        expect($message)->toContain('could not be fetched')
            ->and($message)->not->toContain('resolve host');
    });

    it('does not resolve the host when the pin is missing from the request', function () {
        // The control: the same request without a pin fails at DNS, which is
        // what makes the assertion above mean something.
        try {
            (new CurlTransport())('https://pinned.invalid/cat.png', 'other.invalid', '127.0.0.1', 1024);
            $message = 'no failure';
        } catch (FetchRefused $e) {
            $message = $e->getMessage();
        }

        expect($message)->toContain('resolve host');
    });
});
