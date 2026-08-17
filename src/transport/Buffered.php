<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\transport;

use JsonException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Answers a suspended fiber with one complete JSON-RPC batch instead of a
 * server-sent event stream.
 *
 * WHY: a tool that notifies (reload_mcp always, a live-mode write with an
 * active subscription, any client logging above the session level) suspends the
 * fiber, and the SDK answers that by switching the whole response to SSE. That
 * stream is lazy: its frames are echoed from a callback that only runs when the
 * body is stringified, which happens after the controller has closed its output
 * buffer. The echo therefore lands straight on the SAPI, PHP sends its own
 * default headers first, Craft's `text/event-stream` never arrives, and Yii
 * then dies on HeadersAlreadySentException. The client is handed event-stream
 * framing labelled as HTML, which it cannot read either way. Under FPM there is
 * no version of that which works, because nothing here streams.
 *
 * So the fiber is drained here and now, and everything it produced goes out as
 * one body. The shape is not invented: it is exactly what the SDK's own
 * createJsonResponse() emits, a lone message or a JSON-RPC batch array, which is
 * what a client would have received had the tool not needed to notify.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Buffered extends StreamableHttpTransport {
    /**
     * Resume attempts before the loop gives up.
     *
     * SSE frees each frame as it goes; a buffered batch holds all of them, so
     * this bounds memory where the streaming path relies on the connection
     * ending. It is stricter than the SDK, deliberately: a tool that yields
     * this many times in one call is not a case this transport can serve well,
     * and a warning naming it is more use than an unbounded response.
     */
    private const int MAX_RESUMES = 100;

    private readonly Psr17Factory $psr17;

    /**
     * The factory is held as well as handed up, because the parent keeps its
     * copies private. Supplying one also stops the SDK running PSR-17 discovery
     * per request, here and in two middlewares, when this package already
     * requires nyholm/psr7 outright.
     *
     * @param iterable<object>|null $middleware
     */
    public function __construct(
        ServerRequestInterface $request,
        ?LoggerInterface $logger = null,
        ?iterable $middleware = null,
        ?Psr17Factory $psr17 = null,
    ) {
        $this->psr17 = $psr17 ?? new Psr17Factory();

        parent::__construct(
            request: $request,
            responseFactory: $this->psr17,
            streamFactory: $this->psr17,
            logger: $logger,
            middleware: $middleware,
        );
    }

    #[Override]
    protected function createStreamedResponse(): ResponseInterface {
        $fiber = $this->sessionFiber;
        if ($fiber === null) {
            return $this->createJsonResponse();
        }

        $resumes = 0;
        while ($fiber->isSuspended() && $resumes < self::MAX_RESUMES) {
            $resumes++;
            $this->handleFiberYield($fiber->resume($this->answerTo()), $this->sessionId);
        }

        if ($fiber->isSuspended()) {
            $this->logger->warning('Fiber still suspended after the resume limit; returning what it produced so far.', [
                'limit' => self::MAX_RESUMES,
            ]);
        }

        $final = $fiber->isTerminated() ? $fiber->getReturn() : null;
        $this->sessionFiber = null;

        return $this->batch($final);
    }

    /**
     * What to resume a fiber with.
     *
     * Nothing, unless it is waiting on the client. `elicit()` and `sample()`
     * suspend for a message that cannot arrive inside the POST being answered,
     * so the SDK's stream would poll for the full timeout and pin a worker for
     * two minutes to end up nowhere. Failing immediately, with a reason, is the
     * honest answer for a transport that cannot carry a round trip.
     */
    private function answerTo(): ?Error {
        $pending = $this->getPendingRequests($this->sessionId);
        $first = $pending[0] ?? null;
        if (!is_array($first) || !is_int($first['request_id'] ?? null)) {
            return null;
        }

        return Error::forInternalError(
            'This server cannot ask the client anything over HTTP: the request is answered in one response, '
            . 'so there is nowhere for a reply to arrive. Use the stdio transport for tools that need one.',
            $first['request_id'],
        );
    }

    /**
     * The queued notifications plus the tool's own result, in one body.
     */
    private function batch(mixed $final): ResponseInterface {
        $messages = array_column($this->getOutgoingMessages($this->sessionId), 'message');

        if ($final !== null) {
            try {
                $messages[] = json_encode($final, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->logger->error('Could not encode the fiber result.', ['exception' => $exception]);
            }
        }

        if ($messages === []) {
            return $this->psr17->createResponse(202)->withHeader('Content-Type', 'application/json');
        }

        $body = count($messages) === 1 ? $messages[0] : '[' . implode(',', $messages) . ']';
        $response = $this->psr17->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->psr17->createStream($body));

        return $this->sessionId === null
            ? $response
            : $response->withHeader(self::SESSION_HEADER, $this->sessionId->toRfc4122());
    }
}
