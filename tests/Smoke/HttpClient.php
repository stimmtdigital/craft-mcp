<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use CurlHandle;
use RuntimeException;

/**
 * Drives the MCP server over the plugin's HTTP endpoint exactly as a real
 * client does: bearer token, one POST per message, the session id echoed back
 * on every request after the handshake.
 *
 * WHY it belongs next to the stdio client rather than instead of it: the two
 * transports share almost no code below the JSON-RPC framing. The response to a
 * tool call arrives as a JSON body here and as a line on a pipe there, and the
 * single worst known defect in this plugin only exists on this side, where a
 * tool that notifies makes the SDK switch to SSE mid-response. Anything that
 * arrives outside the JSON body is kept in diagnostics() rather than discarded,
 * because on this transport stray bytes are the symptom.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class HttpClient extends Connection {
    private const string SESSION_HEADER = 'mcp-session-id';

    private const string EVENT_STREAM = 'text/event-stream';

    private ?CurlHandle $curl = null;

    private ?string $sessionId = null;

    /** @var list<string> */
    private array $stray = [];

    /** @var array<string, string> */
    private array $responseHeaders = [];

    public function __construct(
        private readonly string $endpoint,
        private readonly string $token,
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    public function start(): void {
        $curl = curl_init();
        if (!$curl instanceof CurlHandle) {
            throw new RuntimeException("Could not open a connection to {$this->endpoint}");
        }

        $this->curl = $curl;
    }

    public function diagnostics(): string {
        return implode("\n", $this->stray);
    }

    /**
     * Ends the session server side, the way a client that disconnects cleanly
     * does, so a run leaves no session row behind for the next one to inherit.
     */
    public function stop(): void {
        if ($this->curl instanceof CurlHandle && $this->sessionId !== null) {
            $this->transmit('DELETE', null);
        }

        if ($this->curl instanceof CurlHandle) {
            curl_close($this->curl);
        }

        $this->curl = null;
        $this->sessionId = null;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    protected function exchange(array $message, ?int $id): array {
        $body = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = $this->transmit('POST', $body);

        $session = $this->responseHeaders[self::SESSION_HEADER] ?? null;
        if (is_string($session) && $session !== '') {
            $this->sessionId = $session;
        }

        return $id === null ? [] : $this->envelope($response, $id);
    }

    /**
     * @return array{status: int, type: string, body: string}
     */
    private function transmit(string $method, ?string $body): array {
        $curl = $this->curl;
        if (!$curl instanceof CurlHandle) {
            throw new RuntimeException('The connection is not open.');
        }

        $this->responseHeaders = [];
        curl_setopt_array($curl, $this->options($method, $body));

        $received = curl_exec($curl);
        if (!is_string($received)) {
            throw new RuntimeException('Request failed: ' . curl_error($curl));
        }

        return [
            'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'type' => $this->responseHeaders['content-type'] ?? '',
            'body' => $received,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function options(string $method, ?string $body): array {
        return [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body ?? '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $this->headers(),
            CURLOPT_HEADERFUNCTION => $this->headerCollector(),
        ];
    }

    /**
     * @return list<string>
     */
    private function headers(): array {
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json, ' . self::EVENT_STREAM,
            'Mcp-Protocol-Version: ' . self::PROTOCOL_VERSION,
        ];

        if ($this->sessionId !== null) {
            $headers[] = 'Mcp-Session-Id: ' . $this->sessionId;
        }

        return $headers;
    }

    private function headerCollector(): callable {
        return function (CurlHandle $curl, string $line): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $this->responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        };
    }

    /**
     * @param array{status: int, type: string, body: string} $response
     * @return array<string, mixed>
     */
    private function envelope(array $response, int $id): array {
        $this->guard($response);

        $messages = $this->messages($response);
        foreach ($messages as $message) {
            // Anything else on the way is a server-initiated message, stepped
            // over the same way the stdio client steps over one.
            if (($message['id'] ?? null) === $id) {
                return $message;
            }
        }

        $refusal = $this->refusal($messages);
        if ($refusal !== null) {
            return $refusal;
        }

        throw new RuntimeException("No response {$id} in a {$response['status']} {$response['type']} body: " . $this->excerpt($response['body']));
    }

    /**
     * A request refused before it was dispatched (a bad token, an unknown
     * protocol version) is answered with a null id, which is what the spec asks
     * for and what the endpoint does. Reported as the error it is rather than
     * as a response that never came.
     *
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>|null
     */
    private function refusal(array $messages): ?array {
        foreach ($messages as $message) {
            if (($message['id'] ?? null) === null && isset($message['error'])) {
                return $message;
            }
        }

        return null;
    }

    /**
     * The failure modes that are about the transport rather than the message.
     *
     * Event-stream framing on a response that does not declare it is the known
     * HTTP defect, named here so the register can pin it by its symptom: the
     * frames were echoed straight to the SAPI, which made PHP send its own
     * default headers, so the content type the server meant to set never
     * arrived and no client can read the body. Recorded as stray output too,
     * because that is exactly what it is.
     *
     * @param array{status: int, type: string, body: string} $response
     */
    private function guard(array $response): void {
        if (!str_contains($response['type'], self::EVENT_STREAM) && str_starts_with(ltrim($response['body']), 'event:')) {
            $this->stray[] = $this->excerpt($response['body']);

            throw new RuntimeException("SSE frames escaped the response: {$response['status']} {$response['type']} carrying event-stream framing, which no client can read");
        }

        if (trim($response['body']) === '') {
            throw new RuntimeException("empty {$response['status']} body");
        }
    }

    /**
     * @param array{status: int, type: string, body: string} $response
     * @return list<array<string, mixed>>
     */
    private function messages(array $response): array {
        $payloads = str_contains($response['type'], self::EVENT_STREAM)
            ? $this->frames($response['body'])
            : [$response['body']];

        $messages = [];
        foreach ($payloads as $payload) {
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                $this->stray[] = $this->excerpt($payload);

                continue;
            }

            // A batch arrives as a JSON array of envelopes, a single response as
            // one object; both flatten to a list of messages here.
            $messages = array_merge($messages, array_is_list($decoded) ? $decoded : [$decoded]);
        }

        return array_values(array_filter($messages, 'is_array'));
    }

    /**
     * The data payload of each SSE frame. Bytes outside a frame are stray
     * output, kept for the snapshot rather than dropped.
     *
     * @return list<string>
     */
    private function frames(string $body): array {
        $payloads = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $payloads[] = trim(substr($line, 5));

                continue;
            }

            if (trim($line) !== '' && !str_starts_with($line, 'event:') && !str_starts_with($line, 'id:')) {
                $this->stray[] = $this->excerpt($line);
            }
        }

        return $payloads;
    }

    private function excerpt(string $body): string {
        $trimmed = trim($body);

        return strlen($trimmed) > 200 ? substr($trimmed, 0, 197) . '...' : $trimmed;
    }
}
