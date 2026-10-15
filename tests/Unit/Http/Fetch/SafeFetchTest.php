<?php

declare(strict_types=1);

use stimmt\craft\Mcp\http\fetch\FetchRefused;
use stimmt\craft\Mcp\http\fetch\SafeFetch;
use stimmt\craft\Mcp\http\fetch\TransportResponse;

/**
 * upload_asset can make the server fetch a URL it was handed. Without these
 * rules that is a way to read the server's own network: a cloud metadata
 * endpoint, an admin panel on localhost, a database port. Every rule here is
 * held by a test that names the address it keeps out.
 */
beforeEach(function () {
    $this->dns = ['images.example.com' => ['93.184.216.34']];
    $this->calls = [];
    $this->response = new TransportResponse(200, 'image/png', 'PNGDATA');
    $this->fetch = fn (int $max = 1024): SafeFetch => new SafeFetch(
        resolve: fn (string $host): array => $this->dns[$host] ?? [],
        transport: function (string $url, string $host, string $ip, int $maxBytes) {
            $this->calls[] = compact('url', 'host', 'ip', 'maxBytes');

            return $this->response;
        },
        maxBytes: $max,
    );
});

describe('SafeFetch', function () {
    it('fetches a public https URL, pinned to the address it vetted', function () {
        $result = ($this->fetch)()->fetch('https://images.example.com/cat.png');

        expect($result->body)->toBe('PNGDATA')
            ->and($result->contentType)->toBe('image/png')
            ->and($this->calls[0])->toMatchArray(['host' => 'images.example.com', 'ip' => '93.184.216.34']);
    });

    it('refuses anything but https', function (string $url) {
        expect(fn () => ($this->fetch)()->fetch($url))->toThrow(FetchRefused::class, 'https');
    })->with([['http://images.example.com/cat.png'], ['ftp://images.example.com/cat.png'], ['file:///etc/passwd']]);

    it('refuses addresses inside the server\'s own network', function (string $ip) {
        $this->dns['images.example.com'] = [$ip];

        expect(fn () => ($this->fetch)()->fetch('https://images.example.com/cat.png'))->toThrow(FetchRefused::class, 'public')
            ->and($this->calls)->toBe([]);
    })->with([
        'loopback' => ['127.0.0.1'],
        'private 10/8' => ['10.0.0.5'],
        'private 192.168/16' => ['192.168.1.10'],
        'private 172.16/12' => ['172.16.4.4'],
        'cloud metadata (link-local)' => ['169.254.169.254'],
        'carrier-grade NAT' => ['100.64.0.1'],
        'unspecified' => ['0.0.0.0'],
        'IPv6 loopback' => ['::1'],
        'IPv6 unique local' => ['fd00::1'],
        'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
    ]);

    it('refuses an IP literal in the URL the same way', function () {
        expect(fn () => ($this->fetch)()->fetch('https://169.254.169.254/latest/meta-data/'))->toThrow(FetchRefused::class, 'public');
    });

    // A name that answers with one public and one private address could be
    // connected to either; all of them have to be safe.
    it('refuses a host with any private address among its answers', function () {
        $this->dns['images.example.com'] = ['93.184.216.34', '10.0.0.5'];

        expect(fn () => ($this->fetch)()->fetch('https://images.example.com/cat.png'))->toThrow(FetchRefused::class, 'public');
    });

    it('refuses a host that does not resolve', function () {
        expect(fn () => ($this->fetch)()->fetch('https://nowhere.example.com/cat.png'))->toThrow(FetchRefused::class, 'resolve');
    });

    it('refuses credentials and ports other than 443 in the URL', function (string $url) {
        expect(fn () => ($this->fetch)()->fetch($url))->toThrow(FetchRefused::class);
    })->with([['https://user:pass@images.example.com/cat.png'], ['https://images.example.com:8443/cat.png']]);

    // A redirect is a second URL nobody vetted, so it is not followed.
    it('refuses a redirect instead of following it', function () {
        $this->response = new TransportResponse(302, null, '');

        expect(fn () => ($this->fetch)()->fetch('https://images.example.com/cat.png'))->toThrow(FetchRefused::class, 'redirect');
    });

    it('refuses an error response', function () {
        $this->response = new TransportResponse(404, 'text/html', 'nope');

        expect(fn () => ($this->fetch)()->fetch('https://images.example.com/cat.png'))->toThrow(FetchRefused::class, '404');
    });

    it('hands the transport the size cap, and refuses a body over it', function () {
        $this->response = new TransportResponse(200, 'image/png', str_repeat('x', 11));

        expect(fn () => ($this->fetch)(10)->fetch('https://images.example.com/cat.png'))->toThrow(FetchRefused::class, 'larger')
            ->and($this->calls[0]['maxBytes'])->toBe(10);
    });
});
