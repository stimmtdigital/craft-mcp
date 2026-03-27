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
    private const array LEVEL_PRIORITY = [
        'debug'     => 0,
        'info'      => 1,
        'notice'    => 2,
        'warning'   => 3,
        'error'     => 4,
        'critical'  => 5,
        'alert'     => 6,
        'emergency' => 7,
    ];

    private ?DateTimeZone $timezone = null;

    public function __construct(
        private readonly string $logPath,
        private readonly string $minLevel = 'error',
    ) {
        if (!array_key_exists($minLevel, self::LEVEL_PRIORITY)) {
            throw new \InvalidArgumentException(
                "Invalid log level '{$minLevel}'. Must be one of: " . implode(', ', array_keys(self::LEVEL_PRIORITY))
            );
        }

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
        if ($this->levelPriority($level) < $this->levelPriority($this->minLevel)) {
            return;
        }

        $timestamp = $this->getTimestamp();
        $levelUpper = strtoupper($this->levelToString($level));
        $contextJson = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';

        $line = "[{$timestamp}] [{$levelUpper}] {$message}{$contextJson}\n";

        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }

    private function levelPriority(mixed $level): int {
        return self::LEVEL_PRIORITY[strtolower($this->levelToString($level))] ?? 3;
    }

    private function levelToString(mixed $level): string {
        if (is_string($level)) {
            return $level;
        }

        if (is_object($level) && method_exists($level, '__toString')) {
            return (string) $level;
        }

        return 'info';
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
