<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Server\Transport\Stdio\RunnerControl;
use Mcp\Server\Transport\Stdio\RunnerState;

/**
 * Handles Unix signals for graceful shutdown and restart.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SignalHandler {
    private bool $restartRequested = false;

    private bool $shutdownRequested = false;

    /**
     * Check if pcntl extension is available.
     */
    public static function isSupported(): bool {
        return function_exists('pcntl_signal');
    }

    /**
     * Register signal handlers.
     */
    public function register(): void {
        if (!self::isSupported()) {
            return;
        }

        pcntl_async_signals(true);

        // SIGHUP: Graceful restart (reload code)
        pcntl_signal(SIGHUP, function () {
            $this->log('SIGHUP received, scheduling restart...');
            $this->restartRequested = true;
            $this->stopTransport();
        });

        // SIGTERM: Graceful shutdown
        pcntl_signal(SIGTERM, function () {
            $this->log('SIGTERM received, shutting down...');
            $this->shutdownRequested = true;
            $this->stopTransport();
        });

        // SIGINT: Ctrl+C shutdown
        pcntl_signal(SIGINT, function () {
            $this->log('SIGINT received, shutting down...');
            $this->shutdownRequested = true;
            $this->stopTransport();
        });
    }

    /**
     * Check if restart was requested.
     */
    public function shouldRestart(): bool {
        return $this->restartRequested;
    }

    /**
     * Check if shutdown was requested.
     */
    public function shouldShutdown(): bool {
        return $this->shutdownRequested;
    }

    /**
     * Restart the current process.
     *
     * Uses pcntl_exec to replace the current process with a fresh instance.
     *
     * @param array<int, string> $argv Command line arguments
     */
    public function restart(array $argv): never {
        if (!function_exists('pcntl_exec')) {
            $this->log('pcntl_exec not available, cannot restart');
            exit(1);
        }

        $this->log('Restarting...');

        // Reset transport state for new process
        RunnerControl::$state = RunnerState::RUNNING;

        // Replace current process with fresh instance
        pcntl_exec(PHP_BINARY, array_merge([$_SERVER['SCRIPT_FILENAME']], array_slice($argv, 1)));

        // If exec fails, exit with error
        $this->log('Failed to restart');
        exit(1);
    }

    /**
     * Signal the MCP transport to stop.
     */
    private function stopTransport(): void {
        RunnerControl::$state = RunnerState::STOP;
    }

    /**
     * Log a message to stderr.
     */
    private function log(string $message): void {
        fwrite(STDERR, "[MCP] {$message}\n");
    }
}
