<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\insight;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Mcp\Exception\ToolCallException;

/**
 * Packagist's public security-advisory API, asked about a list of package
 * names.
 *
 * WHY only names leave the install: the endpoint is keyed by package and
 * answers with every advisory and its affected range, so the versions never
 * have to be sent; matching happens here. Which packages a site runs is still
 * information about the site, which is why the tool says it calls out.
 *
 * WHY the transport is injected: a test must never reach the network, and
 * the parsing and the refusals are what needs proving, not Guzzle.
 */
final readonly class PackagistAdvisories {
    public const string ENDPOINT = 'https://packagist.org/api/security-advisories/';

    /** Long enough for a slow answer, short enough that an agent is not left hanging on a dead network. */
    public const int TIMEOUT_SECONDS = 10;

    /**
     * @param Closure(list<string>): string $fetch Sends the names, returns the raw response body
     */
    public function __construct(private Closure $fetch) {
    }

    /**
     * POST rather than GET: the API takes packages[] as a request parameter
     * either way, and a full project's list would overrun a URL.
     */
    public static function overHttp(ClientInterface $client): self {
        return new self(static function (array $names) use ($client): string {
            try {
                $response = $client->request('POST', self::ENDPOINT, [
                    'form_params' => ['packages' => $names],
                    'timeout' => self::TIMEOUT_SECONDS,
                    'connect_timeout' => self::TIMEOUT_SECONDS,
                    'headers' => ['Accept' => 'application/json'],
                ]);
            } catch (GuzzleException $exception) {
                throw new ToolCallException(
                    'Could not get advisories from packagist.org: ' . self::withoutCredentials($exception->getMessage())
                    . '. Check that this server can reach packagist.org over HTTPS (proxy, firewall), then try again.',
                    $exception->getCode(),
                    $exception,
                );
            }

            return (string) $response->getBody();
        });
    }

    /**
     * @param list<string> $names
     * @return array<string, mixed> Package name => list of advisories, as Packagist sent them
     */
    public function forPackages(array $names): array {
        if ($names === []) {
            return [];
        }

        return $this->advisories(($this->fetch)($names));
    }

    /**
     * @return array<string, mixed>
     */
    private function advisories(string $body): array {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ToolCallException('packagist.org answered with something that is not JSON. Try again later.');
        }

        if (!is_array($decoded) || !is_array($decoded['advisories'] ?? null)) {
            throw new ToolCallException(
                'packagist.org answered without an "advisories" object, so nothing was checked. Try again later.',
            );
        }

        return $decoded['advisories'];
    }

    /**
     * A proxy configured as user:pass@host can surface in a cURL error; the
     * credentials must not travel into a tool result with it.
     */
    private static function withoutCredentials(string $message): string {
        return (string) preg_replace('#(//)[^/@\s]+@#', '$1***@', $message);
    }
}
