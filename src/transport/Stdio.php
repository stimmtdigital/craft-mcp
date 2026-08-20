<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\transport;

use Mcp\Server\Transport\StdioTransport;
use Override;
use Psr\Log\LoggerInterface;
use stimmt\craft\Mcp\support\SignalHandler;

/**
 * The stdio transport, with two corrections the SDK's own cannot make.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Stdio extends StdioTransport {
    public function __construct(
        private readonly SignalHandler $signals,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct(logger: $logger);
    }

    /**
     * Keeps the client pipes open when a restart is about to replace this
     * process.
     *
     * `Server::run()` closes the transport in a finally, and closing this one
     * means `fclose()` on file descriptors 0 and 1. Our SIGHUP handling then
     * `pcntl_exec`s a fresh image, which inherits the descriptor table, so the
     * new server started with its own stdin and stdout already closed: it could
     * neither read a request nor write a reply, and the client saw the
     * connection hang until it timed out. That path is what `reload_mcp`'s own
     * hint text tells an agent to use, so following our documented advice broke
     * the session every time.
     */
    #[Override]
    public function close(): void {
        if ($this->signals->shouldRestart()) {
            return;
        }

        parent::close();
    }

    /**
     * Declines to read a new request while one is still in flight.
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
     */
    #[Override]
    protected function processInput(): void {
        if ($this->busy()) {
            return;
        }

        parent::processInput();
    }

    private function busy(): bool {
        $fiber = $this->sessionFiber;

        return $fiber !== null
            && $fiber->isSuspended()
            && $this->getPendingRequests($this->sessionId) === [];
    }
}
