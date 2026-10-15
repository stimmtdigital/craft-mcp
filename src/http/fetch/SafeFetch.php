<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\fetch;

use Closure;

/**
 * Fetches a URL for upload_asset without letting it reach the server's own
 * network.
 *
 * WHY each rule: a server that fetches a URL on request can be pointed at what
 * only it can see (a cloud metadata endpoint at 169.254.169.254, an admin
 * panel on localhost, a database on the private network) and hand the answer
 * back. So:
 *
 * - https only, on port 443, with no credentials in the URL;
 * - the host is resolved here and every address it answers with must be a
 *   global one (PHP's FILTER_FLAG_GLOBAL_RANGE: no loopback, private,
 *   link-local, carrier-grade NAT, reserved or IPv4-mapped addresses), since a
 *   connection could land on any of them;
 * - the connection is pinned to an address vetted here, so a DNS answer that
 *   changes between this check and the request (rebinding) cannot swap it;
 * - redirects are not followed, since the target would be a URL nobody vetted;
 * - the body is capped, and the transport stops reading past the cap.
 *
 * Resolution and the transport are injected so every rule is testable without
 * a network; DnsResolver and CurlTransport are the real ones.
 *
 * @author stimmt.digital
 */
final readonly class SafeFetch {
    /**
     * @param Closure(string): string[] $resolve host to every address it answers with
     * @param Closure(string, string, string, int): TransportResponse $transport url, host, pinned ip, max bytes
     */
    public function __construct(
        private Closure $resolve,
        private Closure $transport,
        private int $maxBytes,
    ) {
    }

    public function fetch(string $url): TransportResponse {
        $host = $this->host($url);
        $ip = $this->vettedAddress($host);
        $response = ($this->transport)($url, $host, $ip, $this->maxBytes);

        if ($response->status >= 300 && $response->status < 400) {
            throw new FetchRefused("The URL answered with a redirect ({$response->status}), which is not followed: the target would be a URL nobody checked. Pass the final URL instead.");
        }

        if ($response->status !== 200) {
            throw new FetchRefused("The URL answered with HTTP {$response->status}, not a file.");
        }

        if (strlen($response->body) > $this->maxBytes) {
            throw new FetchRefused("The file is larger than the {$this->maxBytes}-byte limit (the assetUploadMaxBytes setting).");
        }

        return $response;
    }

    private function host(string $url): string {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            throw new FetchRefused('Only https URLs are fetched.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchRefused('URLs carrying credentials are not fetched.');
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw new FetchRefused('Only the default https port (443) is fetched.');
        }

        return trim(strtolower($parts['host']), '[]');
    }

    private function vettedAddress(string $host): string {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);
        if ($addresses === []) {
            throw new FetchRefused("The host {$host} does not resolve to an address.");
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw new FetchRefused("The host {$host} resolves to an address that is not public ({$address}); only files on the public internet are fetched.");
            }
        }

        return $addresses[0];
    }
}
