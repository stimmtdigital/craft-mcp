<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use JsonException;
use RuntimeException;

/**
 * Drives the MCP server over stdio exactly as a real client does: one process,
 * one session, newline delimited JSON-RPC.
 *
 * WHY this exists rather than a Pest test: the unit suite asserts source
 * structure, which is why a fatal in the output layer once broke 19 tools while
 * the suite stayed green. Only the wire tells the truth about whether a tool
 * answers, so the harness speaks the wire.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Client {
    private const string PROTOCOL_VERSION = '2025-06-18';

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private int $nextId = 1;

    /**
     * Server-initiated messages seen while waiting for a response. Kept rather
     * than dropped: a tool that notifies is exercising the fiber path, and a
     * missing notification is as much a regression as a bad result.
     *
     * @var list<array<string, mixed>>
     */
    private array $notifications = [];

    public function __construct(
        private readonly string $command,
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    public function start(): void {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($this->command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Could not start the server with: {$this->command}");
        }

        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
    }

    /**
     * The handshake. Returns the initialize result so the harness can record
     * the advertised capabilities, which are themselves a regression surface.
     *
     * @return array<string, mixed>
     */
    public function initialize(): array {
        $result = $this->request('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
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

        $this->write([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        return $this->awaitResponse($id);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function notify(string $method, array $params): void {
        $this->write(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function takeNotifications(): array {
        $seen = $this->notifications;
        $this->notifications = [];

        return $seen;
    }

    public function isRunning(): bool {
        if (!is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return $status['running'];
    }

    public function stderrOutput(): string {
        if (!is_resource($this->stderr)) {
            return '';
        }

        return (string) stream_get_contents($this->stderr);
    }

    public function stop(): void {
        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->process = null;
        $this->stdin = null;
        $this->stdout = null;
        $this->stderr = null;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function write(array $message): void {
        if (!is_resource($this->stdin)) {
            throw new RuntimeException('The server is not running.');
        }

        $line = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        fwrite($this->stdin, $line . "\n");
        fflush($this->stdin);
    }

    /**
     * Reads until the response carrying $id arrives. Anything else on the way
     * is a server-initiated message and is kept.
     *
     * @return array<string, mixed>
     */
    private function awaitResponse(int $id): array {
        $deadline = microtime(true) + $this->timeoutSeconds;

        while (microtime(true) < $deadline) {
            $line = $this->readLine($deadline);
            if ($line === null) {
                break;
            }

            $message = $this->decode($line);
            if ($message === null) {
                continue;
            }

            if (($message['id'] ?? null) === $id) {
                return $message;
            }

            $this->notifications[] = $message;
        }

        throw new RuntimeException("Timed out waiting for response {$id}.");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $line): ?array {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Not our protocol: stray output on stdout is itself worth seeing,
            // but it is not a message and must not derail the run.
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function readLine(float $deadline): ?string {
        if (!is_resource($this->stdout)) {
            return null;
        }

        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = fgets($this->stdout);
            if ($chunk === false) {
                if (feof($this->stdout)) {
                    return $buffer === '' ? null : $buffer;
                }

                $read = [$this->stdout];
                $write = null;
                $except = null;
                stream_select($read, $write, $except, 0, 50000);

                continue;
            }

            $buffer .= $chunk;
            if (str_ends_with($buffer, "\n")) {
                return trim($buffer);
            }
        }

        return null;
    }
}
