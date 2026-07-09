<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use craft\web\Request as CraftRequest;
use craft\web\Response as CraftResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Converts between Craft's Yii request/response and PSR-7. Pure conversion:
 * no MCP, token, or transport knowledge.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Bridge {
    public function toPsr7(CraftRequest $request): ServerRequestInterface {
        $factory = new Psr17Factory();
        $psr7 = $factory->createServerRequest(
            $request->getMethod(),
            $request->getHostInfo() . $request->getUrl(),
            $_SERVER,
        );

        foreach ($request->getHeaders()->toArray() as $name => $values) {
            $psr7 = $this->withHeader($psr7, (string) $name, $values);
        }

        return $psr7->withBody($factory->createStream($request->getRawBody()));
    }

    /**
     * The first value replaces whatever the PSR-7 factory may have derived
     * on construction (notably Host, which Nyholm's Request sets from the
     * URI); withAddedHeader alone would duplicate it instead of overriding
     * it, and a doubled Host header fails the SDK's DNS-rebinding check.
     * Remaining values append, preserving genuinely multi-valued headers.
     */
    private function withHeader(ServerRequestInterface $psr7, string $name, mixed $values): ServerRequestInterface {
        $values = (array) $values;
        if ($values === []) {
            return $psr7;
        }

        $psr7 = $psr7->withHeader($name, (string) array_shift($values));
        foreach ($values as $value) {
            $psr7 = $psr7->withAddedHeader($name, (string) $value);
        }

        return $psr7;
    }

    public function apply(ResponseInterface $psr7, CraftResponse $response): CraftResponse {
        $response->format = CraftResponse::FORMAT_RAW;
        $response->setStatusCode($psr7->getStatusCode());
        foreach ($psr7->getHeaders() as $name => $values) {
            $response->getHeaders()->set($name, implode(', ', $values));
        }

        $response->content = (string) $psr7->getBody();

        return $response;
    }
}
