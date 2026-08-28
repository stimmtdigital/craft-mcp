<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\transport;

use Mcp\Server\Transport\Stdio\RunnerControl;
use Mcp\Server\Transport\Stdio\RunnerState;
use Mcp\Server\Transport\StdioTransport;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stimmt\craft\Mcp\support\SignalHandler;

/**
 * The stdio transport, with four corrections the SDK's own cannot make.
 *
 * Two of them are one mistake seen from both ends. `listen()` asks for
 * non-blocking streams and then reads and writes as though they were blocking:
 * a write is only ever partly taken, and a read only ever partly given. Inbox
 * and Outbox carry each half across ticks; both hold their own reasoning.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Stdio extends StdioTransport {
    /**
     * How long shutdown may spend getting the backlog to the client before it
     * gives up on it. Generous against a client that is merely slow, finite
     * against one that has walked away and would otherwise hold the process
     * open for good.
     */
    private const float SHUTDOWN_SECONDS = 5.0;

    /**
     * The pause taken on a tick where nothing arrived, matching the SDK's own,
     * so an idle session costs nothing.
     */
    private const int IDLE_MICROSECONDS = 50000;

    private readonly Outbox $outbox;

    private readonly Inbox $inbox;

    public function __construct(
        private readonly SignalHandler $signals,
        ?LoggerInterface $logger = null,
    ) {
        $logger ??= new NullLogger();

        // The parent's output is the seam. Every frame it emits goes through a
        // private writeLine(), so replacing the resource is the only way to
        // reach all three write paths rather than the one send() covers.
        $this->outbox = new Outbox(STDOUT, STDIN, $logger);
        $this->inbox = new Inbox(STDIN, $logger);

        // Input is named rather than defaulted, so the stream these two work on
        // is visibly the one the parent tests for EOF.
        parent::__construct(input: STDIN, output: $this->outbox->resource(), logger: $logger);
    }

    /**
     * Keeps the client pipes open when a restart is about to replace this
     * process, and gets anything still owed to the client out first.
     *
     * `Server::run()` closes the transport in a finally, and closing this one
     * means `fclose()` on file descriptors 0 and 1. Our SIGHUP handling then
     * `pcntl_exec`s a fresh image, which inherits the descriptor table, so the
     * new server started with its own stdin and stdout already closed: it could
     * neither read a request nor write a reply, and the client saw the
     * connection hang until it timed out. That path is what `reload_mcp`'s own
     * hint text tells an agent to use, so following our documented advice broke
     * the session every time.
     *
     * The flush comes first on both paths. `listen()` returns one tick after
     * the last answer was written, so that answer is normally still in the
     * outbox; on the restart path it would be lost outright, because
     * `pcntl_exec` replaces the image that holds it.
     */
    #[Override]
    public function close(): void {
        $this->outbox->flush(self::SHUTDOWN_SECONDS);

        if ($this->signals->shouldRestart()) {
            return;
        }

        parent::close();
        // parent::close() closes the outbox buffer, since that is what it holds
        // as its output. The real stdout is ours to close.
        $this->outbox->close();
    }

    /**
     * Moves buffered output on to the client, then declines to read a new
     * request while one is still in flight.
     *
     * `listen()` calls this before `processFiber()` on every tick, so a second
     * request arriving while a fiber is suspended used to create a second fiber
     * and overwrite the first in the transport's single slot. The first request
     * was then never answered and its caller blocked until its own timeout,
     * with nothing logged. Leaving the line unread is correct backpressure: the
     * stream is non-blocking and the loop keeps turning, so the pending fiber is
     * resumed on this very tick and the line is read once it finishes.
     *
     * The pending-request case is deliberately excluded. A fiber waiting on the
     * client (elicitation, sampling) is waiting for a message that arrives
     * through this method, so guarding it there would deadlock until the
     * timeout. That is the trap in this fix, and it is the same condition
     * `processFiber()` itself branches on.
     *
     * The drain runs ahead of that guard rather than behind it, because output
     * must keep moving while a fiber is suspended: this tick is the only place
     * the outbox is served, and a backlog held back until the fiber finishes
     * would be exactly the stall it exists to prevent. Only a client that has
     * stopped reading altogether ends the loop here; Outbox holds that
     * reasoning.
     *
     * The read is the inbox's rather than the parent's, because the parent
     * hands whatever `fgets()` returned straight to the dispatcher and a
     * message that arrived in pieces is not one; Inbox holds that reasoning.
     */
    #[Override]
    protected function processInput(): void {
        if (!$this->outbox->drain()) {
            $this->stop();

            return;
        }

        if ($this->busy()) {
            return;
        }

        $message = $this->inbox->next();
        if ($message === null) {
            usleep(self::IDLE_MICROSECONDS);

            return;
        }

        $this->handleMessage($message, $this->sessionId);
    }

    private function busy(): bool {
        $fiber = $this->sessionFiber;

        return $fiber !== null
            && $fiber->isSuspended()
            && $this->getPendingRequests($this->sessionId) === [];
    }

    /**
     * Ends the listen loop from inside a tick. `RunnerControl`'s state is the
     * only switch that loop reads, and `SignalHandler` already drives the same
     * one for SIGTERM and SIGINT. STOP rather than STOP_AND_END_SESSION for the
     * same reason it does: `close()` ends the session either way.
     */
    private function stop(): void {
        RunnerControl::$state = RunnerState::STOP;
    }
}
