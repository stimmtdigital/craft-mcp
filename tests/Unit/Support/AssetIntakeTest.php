<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\http\fetch\SafeFetch;
use stimmt\craft\Mcp\http\fetch\TransportResponse;
use stimmt\craft\Mcp\support\AssetIntake;

/**
 * How a file reaches upload_asset: fetched from a URL through SafeFetch, or
 * passed as base64. Exactly one, under the same size cap either way.
 */
beforeEach(function () {
    $this->fetched = [];
    $this->intake = fn (int $max = 16): AssetIntake => new AssetIntake(new SafeFetch(
        resolve: fn (string $host): array => ['93.184.216.34'],
        transport: function (string $url) {
            $this->fetched[] = $url;

            return new TransportResponse(200, 'image/png', 'FETCHED');
        },
        maxBytes: $max,
    ), $max);
});

describe('AssetIntake', function () {
    it('fetches a URL through SafeFetch', function () {
        expect(($this->intake)()->bytes('https://images.example.com/cat.png', null))->toBe('FETCHED')
            ->and($this->fetched)->toBe(['https://images.example.com/cat.png']);
    });

    it('turns a SafeFetch refusal into a tool error that keeps its reason', function () {
        expect(fn () => ($this->intake)()->bytes('http://images.example.com/cat.png', null))
            ->toThrow(ToolCallException::class, 'Only https URLs are fetched.');
    });

    it('decodes base64 data, with or without a data: URI prefix', function (string $data) {
        expect(($this->intake)()->bytes(null, $data))->toBe('<svg/>');
    })->with([['PHN2Zy8+'], ['data:image/svg+xml;base64,PHN2Zy8+'], ["PHN2\nZy8+"]]);

    it('refuses data that is not base64', function () {
        expect(fn () => ($this->intake)()->bytes(null, 'not base64!'))->toThrow(ToolCallException::class, 'base64');
    });

    it('refuses data over the size cap', function () {
        expect(fn () => ($this->intake)(4)->bytes(null, base64_encode('12345')))
            ->toThrow(ToolCallException::class, '4-byte limit');
    });

    it('refuses an empty file', function () {
        expect(fn () => ($this->intake)()->bytes(null, ''))->toThrow(ToolCallException::class, 'empty');
    });

    it('needs exactly one of url and data', function (?string $url, ?string $data) {
        expect(fn () => ($this->intake)()->bytes($url, $data))->toThrow(ToolCallException::class, 'exactly one')
            ->and($this->fetched)->toBe([]);
    })->with([[null, null], ['https://images.example.com/cat.png', 'PHN2Zy8+']]);
});
