<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\transport;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Assembles whole JSON-RPC messages out of a stdin that hands them over in
 * pieces.
 *
 * WHY: `listen()` sets stdin non-blocking, and on a non-blocking stream
 * `fgets()` returns whatever has arrived so far, newline or not. The SDK reads
 * one and treats it as a message regardless, so a request split across two
 * reads becomes two syntax errors and is never answered. Proven on the wire: an
 * 8 KB request delivered whole is answered, and the same request delivered in
 * two writes gets back `{"code":-32700,"message":"Syntax error"}` twice.
 *
 * It is the same mistake as the one Outbox corrects, in the other direction:
 * the transport asks for non-blocking streams and then writes code that assumes
 * blocking semantics on both ends. A write is only ever partly taken, and a
 * read only ever partly given, and both have to be carried across ticks.
 *
 * Reachable in ordinary use. A pipe delivers a write of up to PIPE_BUF (4 KiB)
 * atomically, so small requests are safe by luck; a socket promises nothing at
 * all, and a request over a few KB, which any sizeable `create_entry` or
 * `tinker` call is, can arrive in pieces on either.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Inbox {
    /**
     * How long a single message may be before it is treated as a client that
     * will never send a newline.
     *
     * Holding an unfinished message across ticks is what makes this class
     * work, and also what makes it a place to grow without limit, so the two
     * arrive together. The ceiling clears any real request by a wide margin: a
     * `create_entry` carrying a large Matrix field is measured in megabytes,
     * not tens of them.
     */
    private const int MAX_MESSAGE = 16777216;

    /**
     * What has arrived of the message currently being assembled.
     */
    private string $partial = '';

    /**
     * Whether the rest of an abandoned message is still being thrown away. The
     * only way back into step with a client after a message is refused is to
     * read on to the next newline; treating the tail as a message would answer
     * with errors nobody asked for.
     */
    private bool $resyncing = false;

    /**
     * @param resource $input
     */
    public function __construct(
        private $input,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The next complete message, or null when the client has not finished one.
     * Null means the stream is empty right now, so the caller can idle on it;
     * a message still being assembled keeps reading rather than returning,
     * because pausing between the pieces of one message would pace a large
     * request at one chunk per idle tick.
     */
    public function next(): ?string {
        while (($chunk = fgets($this->input)) !== false) {
            $message = $this->absorb($chunk);
            if ($message !== null) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Takes one read, and returns a message only once its newline has arrived.
     */
    private function absorb(string $chunk): ?string {
        $ended = str_ends_with($chunk, "\n");

        if ($this->resyncing) {
            $this->resyncing = !$ended;

            return null;
        }

        $this->partial .= $chunk;

        if ($ended) {
            return $this->take();
        }

        if (strlen($this->partial) <= self::MAX_MESSAGE) {
            return null;
        }

        $this->logger->error('Refusing a message with no end in sight; skipping to the next one.', [
            'held' => strlen($this->partial),
            'ceiling' => self::MAX_MESSAGE,
        ]);

        $this->partial = '';
        $this->resyncing = true;

        return null;
    }

    /**
     * The assembled message, trimmed the way the SDK trims it. A blank line is
     * not a message and is not worth waking anything for.
     */
    private function take(): ?string {
        $message = trim($this->partial);
        $this->partial = '';

        return $message === '' ? null : $message;
    }
}
