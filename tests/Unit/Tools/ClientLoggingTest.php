<?php

declare(strict_types=1);

use Mcp\Capability\Logger\ClientLogger;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Server\ClientGateway;
use Mcp\Server\Protocol;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;

/**
 * ClientGateway::notify() suspends a Fiber with the notification payload
 * instead of writing to a real transport, so starting the log call inside
 * our own Fiber and reading the value handed to Fiber::suspend() tells us
 * exactly what would reach the client, with no server or transport running.
 *
 * @return array{type: string, notification: LoggingMessageNotification, session_id: string}|null
 */
function captureClientLogNotification(ClientLogger $logger, string $level, string $message): ?array {
    $fiber = new Fiber(function () use ($logger, $level, $message): void {
        $logger->{$level}($message);
    });

    return $fiber->start();
}

describe('ClientLogger wire path (RequestContext::getClientLogger)', function () {
    it('is constructible from Session and ClientGateway alone, with no Craft app and no live transport', function () {
        $session = new Session(new InMemorySessionStore());
        $gateway = new ClientGateway($session);
        $logger = new ClientLogger($gateway, $session);

        expect($logger)->toBeInstanceOf(ClientLogger::class);
    });

    it('delivers an interpolated message string to the notification data field', function () {
        $session = new Session(new InMemorySessionStore());
        $session->set(Protocol::SESSION_LOGGING_LEVEL, LoggingLevel::Debug->value);
        $logger = new ClientLogger(new ClientGateway($session), $session);

        $suspended = captureClientLogNotification($logger, 'info', 'SQL query returned 7 rows');

        expect($suspended['notification'])->toBeInstanceOf(LoggingMessageNotification::class)
            ->and($suspended['notification']->level)->toBe(LoggingLevel::Info)
            ->and($suspended['notification']->data)->toBe('SQL query returned 7 rows');
    });

    it('drops messages below the negotiated severity, so nothing is sent at all', function () {
        $session = new Session(new InMemorySessionStore());
        $session->set(Protocol::SESSION_LOGGING_LEVEL, LoggingLevel::Warning->value);
        $logger = new ClientLogger(new ClientGateway($session), $session);

        $suspended = captureClientLogNotification($logger, 'info', 'SQL query returned 7 rows');

        expect($suspended)->toBeNull();
    });

    it('never forwards a second PSR-3 context array; only what is interpolated into the message survives', function () {
        $session = new Session(new InMemorySessionStore());
        $session->set(Protocol::SESSION_LOGGING_LEVEL, LoggingLevel::Debug->value);
        $logger = new ClientLogger(new ClientGateway($session), $session);

        $fiber = new Fiber(function () use ($logger): void {
            // Mirrors the bare-label-plus-context-array shape that shipped
            // in the first version of this feature: the array is silently
            // dropped by ClientLogger::log(), which only forwards $message.
            $logger->debug('Tinker code', ['code' => 'echo 1;']);
        });
        $suspended = $fiber->start();

        expect($suspended['notification']->data)->toBe('Tinker code')
            ->and($suspended['notification']->data)->not->toContain('echo 1;');
    });
});

describe('Tool client-logger calls interpolate their data into the message string', function () {
    $toolFiles = [
        'TinkerTools.php' => dirname(__DIR__, 3) . '/src/tools/TinkerTools.php',
        'DatabaseTools.php' => dirname(__DIR__, 3) . '/src/tools/DatabaseTools.php',
        'DebugTools.php' => dirname(__DIR__, 3) . '/src/tools/DebugTools.php',
    ];

    it('never passes a second PSR-3 context array to a getClientLogger() call', function (string $file) use ($toolFiles) {
        $source = (string) file_get_contents($toolFiles[$file]);

        // A second argument shaped like `, ['key' => ...]` is silently
        // dropped by ClientLogger::log() (see ClientLoggerWireTest above),
        // so no call may carry data that way; everything must be
        // interpolated directly into the single message string.
        expect($source)->not->toMatch('/getClientLogger\(\)\?->\w+\([^)]*,\s*\[/');
    })->with(['TinkerTools.php', 'DatabaseTools.php', 'DebugTools.php']);

    it('interpolates the blocked pattern and tinker source into TinkerTools messages', function () use ($toolFiles) {
        $source = (string) file_get_contents($toolFiles['TinkerTools.php']);

        expect($source)->toContain('"Tinker code rejected by security pattern: {$pattern}"')
            ->and($source)->toContain('"Tinker code: {$code}"')
            ->and($source)->toContain("'Tinker execution failed: ' . \$e::class");
    });

    it('interpolates the row count and full SQL text into DatabaseTools::runQuery messages', function () use ($toolFiles) {
        $source = (string) file_get_contents($toolFiles['DatabaseTools.php']);

        expect($source)->toContain('"SQL query text: {$sql}"')
            ->and($source)->toContain('"SQL query returned {$rowCount} rows"');
    });

    it('interpolates the driver, SQL text, and log window into DebugTools messages', function () use ($toolFiles) {
        $source = (string) file_get_contents($toolFiles['DebugTools.php']);

        expect($source)->toContain('"SQL query text: {$trimmedSql}"')
            ->and($source)->toContain('"EXPLAIN issued for driver: {$driver}"')
            ->and($source)->toContain('"Log window read: {$logFile}, last {$lineCount} lines"');
    });
});
