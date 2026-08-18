<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use RuntimeException;

/**
 * One MCP session, whatever carries it.
 *
 * WHY a base class rather than two clients the runner switches on: the harness
 * exists to prove behaviour is the same on both transports, and it can only
 * prove that if the plan, the assertions and the register are driven through
 * one contract. Everything transport-specific lives below exchange(); the
 * JSON-RPC framing above it is shared, so a divergence in results is a
 * divergence in the server rather than in the harness.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
abstract class Client {
    protected const string PROTOCOL_VERSION = '2025-06-18';

    private int $nextId = 1;

    abstract public function start(): void;

    abstract public function stop(): void;

    /**
     * Whatever the transport carried beside the protocol: the server's stderr
     * over stdio, bytes outside the JSON body over HTTP. Recorded in the
     * snapshot because output where the protocol expects none is itself a
     * defect worth seeing.
     */
    abstract public function diagnostics(): string;

    /**
     * Writes one message and returns the envelope carrying $id. A null $id is a
     * notification, which has no response and returns an empty array.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    abstract protected function exchange(array $message, ?int $id): array;

    /**
     * The handshake. Returns the initialize result so the harness can record
     * the advertised capabilities, which are themselves a regression surface.
     *
     * @return array<string, mixed>
     */
    public function initialize(): array {
        $result = $this->request('initialize', [
            'protocolVersion' => static::PROTOCOL_VERSION,
            'capabilities' => [],
            'clientInfo' => ['name' => 'craft-mcp-smoke', 'version' => '1'],
        ]);

        $this->notify('notifications/initialized', []);

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> the raw JSON-RPC envelope, so the caller can
     *                              see an error as readily as a result
     */
    public function callTool(string $name, array $arguments): array {
        return $this->send('tools/call', ['name' => $name, 'arguments' => $arguments]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function request(string $method, array $params): array {
        $envelope = $this->send($method, $params);

        if (isset($envelope['error'])) {
            $message = is_array($envelope['error']) ? json_encode($envelope['error']) : 'unknown';

            throw new RuntimeException("{$method} failed: {$message}");
        }

        return is_array($envelope['result'] ?? null) ? $envelope['result'] : [];
    }

    /**
     * Sends a request and returns the whole envelope, error included.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function send(string $method, array $params): array {
        $id = $this->nextId;
        $this->nextId++;

        return $this->exchange([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], $id);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function notify(string $method, array $params): void {
        $this->exchange(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params], null);
    }
}
