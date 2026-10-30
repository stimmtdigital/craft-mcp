<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\services;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * The SDK's initialize answer, with this server's listChanged flags put back.
 *
 * WHY: mcp/sdk 0.9 strips every listChanged flag from the initialize result,
 * reasoning that only its new subscriptions/listen streams carry list changes.
 * This server negotiates 2025-06-18, where notifications/tools/list_changed is
 * an ordinary notification, and reload_mcp sends one after a rescan. A client
 * told the tool list never changes may ignore that notification. Everything
 * else (version negotiation, server info, instructions) stays the SDK's.
 *
 * Registered through Builder::addRequestHandler(): custom handlers are asked
 * before the built-in ones, so this one answers initialize.
 *
 * @implements RequestHandlerInterface<InitializeResult>
 */
final readonly class ListChangedInitialize implements RequestHandlerInterface {
    public function __construct(
        private InitializeHandler $inner,
        private ServerCapabilities $capabilities,
    ) {
    }

    public function supports(Request $request): bool {
        return $this->inner->supports($request);
    }

    /**
     * @return Response<InitializeResult>
     */
    public function handle(Request $request, SessionInterface $session): Response {
        $response = $this->inner->handle($request, $session);
        $result = $response->result;

        return new Response($response->id, new InitializeResult(
            $this->capabilities,
            $result->serverInfo,
            $result->instructions,
            $result->meta,
            $result->protocolVersion,
        ));
    }
}
