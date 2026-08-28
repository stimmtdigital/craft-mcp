<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\transport;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Stands between the SDK's writes and the real stdout, so that answering a
 * client can never stop us reading from it.
 *
 * WHY: StdioTransport writes every frame with a bare `fwrite()` on stdout,
 * discards the return value, and sets only stdin non-blocking. stdout is a
 * pipe holding about 64 KiB, so an answer larger than that blocks inside that
 * fwrite. Blocked there the listen loop never comes back round, which means
 * stdin stops being read; a client still writing the rest of a batch then
 * fills its own pipe and blocks in ITS write, so it never reads the bytes that
 * would let ours finish. Both sides wait forever, fwrite has no timeout, and
 * nothing is logged. `list_entries` at the default page size is 118 KB, so
 * this is ordinary use rather than a stress case.
 *
 * The correction has to catch every write, and `send()` is only one of three
 * paths into the SDK's private `writeLine()`. So what the parent is handed as
 * its output resource is this buffer instead of stdout: it accepts a write
 * whole and returns at once, and the bytes are moved on to the real stdout
 * from the listen loop, where a full pipe means "more next tick" rather than
 * "stop everything".
 *
 * Order is preserved by construction: the backlog is one byte stream, written
 * at the end and delivered from the front.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Outbox {
    /**
     * How much undelivered output still describes a client that is merely
     * behind rather than one that has stopped reading altogether.
     *
     * The ceiling has to clear the worst honest case by a wide margin, because
     * a single answer is never refused: the check runs between writes, on the
     * accumulated backlog, so a legitimately huge response is buffered whole
     * and delivered rather than truncated. What it does catch is a client that
     * pipelined a batch and then went away, where the backlog is bounded only
     * by how many requests it managed to send. Past this the session is over:
     * dropping frames would corrupt the protocol silently, and waiting is the
     * deadlock this class exists to prevent, so the honest move is to log and
     * end it.
     */
    private const int MAX_PENDING = 67108864;

    /**
     * How much is offered to the kernel per write. One pipe's worth, so a
     * write that is refused has genuinely run out of room rather than been
     * handed more than a pipe can hold.
     */
    private const int CHUNK = 65536;

    /**
     * How long one wait for room may last. Short, because the listen loop also
     * resumes fibers, times pending requests out and reads the runner state,
     * and none of that happens while we are here.
     */
    private const int WAIT_MICROSECONDS = 50000;

    /**
     * How long a single drain may spend before handing control back to the
     * listen loop, however much backlog is left.
     */
    private const float TICK_SECONDS = 0.2;

    /** @var resource */
    private $buffer;

    /**
     * How far into the buffer the client has been served. The gap between this
     * and the write position is the backlog.
     */
    private int $delivered = 0;

    private bool $broken = false;

    private bool $overflowed = false;

    /**
     * @param resource $output the real stdout, set non-blocking here because a
     *                         short write is the signal this class acts on, not an error
     * @param resource $incoming stdin. An output buffer watches it because an
     *                           arriving request outranks finishing the current answer: reading it
     *                           is what unblocks a client that has stopped reading us.
     */
    public function __construct(
        private $output,
        private $incoming,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $buffer = fopen('php://memory', 'r+b');
        if ($buffer === false) {
            throw new RuntimeException('Could not open the stdio output buffer.');
        }

        $this->buffer = $buffer;

        stream_set_blocking($this->output, false);
        // Without this PHP can report a write it has only taken into its own
        // buffer, and the backlog would advance past bytes the kernel never saw.
        stream_set_write_buffer($this->output, 0);
    }

    /**
     * What the SDK writes into. Handed to StdioTransport as its output.
     *
     * @return resource
     */
    public function resource() {
        return $this->buffer;
    }

    /**
     * Moves as much of the backlog to stdout as the client will take, and says
     * whether this outbox can still be served. Called on every tick of the
     * listen loop.
     */
    public function drain(): bool {
        if ($this->broken || $this->overflows()) {
            return false;
        }

        $this->pump(microtime(true) + self::TICK_SECONDS, true);

        return !$this->broken;
    }

    /**
     * Gets the backlog out before the stream closes.
     *
     * The SIGHUP restart path depends on this: `pcntl_exec` replaces this
     * image, so bytes still held here are lost rather than delayed. It is also
     * the normal exit path, because `listen()` returns on stdin EOF one tick
     * after the last answer was written, with that answer still in hand.
     *
     * Bounded, because a client that has gone away must not hold the process
     * open, and there is nothing left to gain from waiting on one that has.
     */
    public function flush(float $seconds): void {
        if ($this->broken || $this->overflowed) {
            return;
        }

        $this->pump(microtime(true) + $seconds, false);

        $left = $this->pending();
        if ($left <= 0) {
            return;
        }

        $this->logger->warning('Gave up on undelivered output at shutdown.', [
            'bytes' => $left,
            'seconds' => $seconds,
        ]);
    }

    /**
     * Closes the real stdout. The buffer is the parent's `$output` and is
     * closed by `parent::close()`, so it is deliberately left alone here.
     */
    public function close(): void {
        if (!is_resource($this->output)) {
            return;
        }

        fclose($this->output);
    }

    /**
     * Writes until the backlog is gone, the deadline passes, or the client
     * stops taking bytes. $yieldToInput gives up the moment a request is
     * waiting, which is the whole point on the listen loop and pointless once
     * the session is closing.
     */
    private function pump(float $deadline, bool $yieldToInput): void {
        while ($this->pending() > 0 && microtime(true) < $deadline) {
            if ($this->push() > 0) {
                continue;
            }

            if ($this->broken || $this->interrupted($yieldToInput)) {
                return;
            }
        }
    }

    /**
     * One write, returning how many bytes the kernel took. Zero means the pipe
     * is full, which is information rather than failure.
     */
    private function push(): int {
        $chunk = $this->peek(min($this->pending(), self::CHUNK));
        $written = fwrite($this->output, $chunk);

        if ($written === false) {
            $this->broken = true;
            $this->logger->error('Could not write to stdout; the client is gone.', [
                'pending' => $this->pending(),
            ]);

            return 0;
        }

        $this->delivered += $written;
        $this->compact();

        return $written;
    }

    /**
     * Waits for the client to make room, and reports whether pumping should
     * stop now: because a request is waiting and outranks this, or because a
     * signal cut the wait short and the loop needs to re-read the runner state.
     * A plain timeout is not one of those; the caller's deadline decides that.
     */
    private function interrupted(bool $yieldToInput): bool {
        $read = $yieldToInput ? [$this->incoming] : [];
        $write = [$this->output];
        $except = null;

        $ready = stream_select($read, $write, $except, 0, self::WAIT_MICROSECONDS);

        return $ready === false || $read !== [];
    }

    /**
     * The next bytes owed to the client, without disturbing where the SDK
     * writes: the buffer carries one position for both, so it is borrowed and
     * put back.
     */
    private function peek(int $length): string {
        $writePosition = $this->writePosition();

        fseek($this->buffer, $this->delivered);
        $chunk = (string) fread($this->buffer, $length);
        fseek($this->buffer, $writePosition);

        return $chunk;
    }

    /**
     * Returns the buffer to zero once everything in it has gone out, so a long
     * session does not carry a memory stream full of bytes it will never read
     * again.
     */
    private function compact(): void {
        if ($this->delivered < $this->writePosition()) {
            return;
        }

        ftruncate($this->buffer, 0);
        fseek($this->buffer, 0);
        $this->delivered = 0;
    }

    /**
     * Whether the backlog has grown past what a stalled client explains. Logs
     * once: the loop asks on every tick and a repeated line would bury the
     * answers that did get out.
     */
    private function overflows(): bool {
        $pending = $this->pending();
        if ($pending <= self::MAX_PENDING) {
            return false;
        }

        if (!$this->overflowed) {
            $this->overflowed = true;
            $this->logger->error('Undelivered output passed its ceiling; the client is not reading. Ending the session.', [
                'pending' => $pending,
                'ceiling' => self::MAX_PENDING,
            ]);
        }

        return true;
    }

    private function pending(): int {
        return $this->writePosition() - $this->delivered;
    }

    private function writePosition(): int {
        return (int) ftell($this->buffer);
    }
}
