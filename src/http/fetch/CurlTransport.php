<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\fetch;

use CurlHandle;

/**
 * The real transport behind SafeFetch: one GET over curl, connected to the
 * address SafeFetch vetted.
 *
 * WHY curl and not Guzzle: both rules this class exists for live in curl
 * options. Guzzle hands a streamed request to a handler that ignores them,
 * and its curl handler owns the write callback, so the pin and the cap would
 * depend on Guzzle internals that differ across the versions Craft allows.
 * Craft requires ext-curl, so this adds nothing to install.
 *
 * - CURLOPT_RESOLVE pins host:443 to the vetted address, so curl never asks
 *   DNS again; TLS still verifies the certificate against the host name.
 * - The proxy is switched off, since a proxy resolves the name itself and the
 *   pin would mean nothing.
 * - The write callback stops the transfer one byte past the cap.
 *
 * @author stimmt.digital
 */
final readonly class CurlTransport {
    private const int CONNECT_TIMEOUT_SECONDS = 10;

    private const int TIMEOUT_SECONDS = 30;

    public function __invoke(string $url, string $host, string $ip, int $maxBytes): TransportResponse {
        $body = new CappedBody($maxBytes);
        $handle = curl_init($url);
        if ($handle === false) {
            throw new FetchRefused('The URL could not be fetched: curl could not start.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RESOLVE => [$this->pin($host, $ip)],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'Craft MCP (upload_asset)',
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use ($body): int {
                $body->append($chunk);

                // Any count other than the chunk's length makes curl abort.
                return $body->exceeded() ? 0 : strlen($chunk);
            },
        ]);

        if (curl_exec($handle) === false && !$body->exceeded()) {
            throw new FetchRefused('The URL could not be fetched: ' . curl_error($handle) . '.');
        }

        $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);

        return new TransportResponse(
            curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            is_string($contentType) ? $contentType : null,
            $body->bytes(),
        );
    }

    /**
     * curl's HOST:PORT:ADDRESS form; an IPv6 address goes in brackets.
     */
    private function pin(string $host, string $ip): string {
        $address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? $ip : "[{$ip}]";

        return "{$host}:443:{$address}";
    }
}
