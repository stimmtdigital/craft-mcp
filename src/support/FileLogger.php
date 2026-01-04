<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use craft\config\GeneralConfig;
use craft\services\Config;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * Simple file-based PSR-3 logger for MCP server.
 * Writes logs to a dedicated file, separate from Craft's logging system.
 *
 * Usage:
 *   $logger = new FileLogger('/path/to/mcp-server.log');
 *   $logger->info('Server started');
 *   $logger->error('Tool execution failed', ['tool' => 'tinker', 'error' => $e->getMessage()]);
 */
class FileLogger extends AbstractLogger {
    private ?DateTimeZone $timezone = null;

    public function __construct(private readonly string $logPath) {
        $this->ensureDirectoryExists();
        $this->initializeTimezone();
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void {
        $timestamp = $this->getTimestamp();
        $levelString = is_string($level) ? $level : (is_object($level) && method_exists($level, '__toString') ? (string) $level : 'INFO');
        $levelUpper = strtoupper($levelString);
        $contextJson = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';

        $line = "[{$timestamp}] [{$levelUpper}] {$message}{$contextJson}\n";

        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }

    private function ensureDirectoryExists(): void {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Initialize timezone from Craft's configuration.
     */
    private function initializeTimezone(): void {
        try {
            /** @var Config $configService */
            $configService = Craft::$app->getConfig();

            /** @var GeneralConfig $generalConfig */
            $generalConfig = $configService->getGeneral();
            $configTimezone = $generalConfig->timezone;

            if (is_string($configTimezone) && $configTimezone !== '') {
                $this->timezone = new DateTimeZone($configTimezone);

                return;
            }
        } catch (Throwable) {
            // Craft not available yet, fall through
        }

        // Fall back to system timezone
        $this->timezone = new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Get formatted timestamp in the configured timezone.
     */
    private function getTimestamp(): string {
        $now = new DateTimeImmutable('now', $this->timezone);

        return $now->format('Y-m-d H:i:s');
    }
}
