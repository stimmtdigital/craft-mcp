<?php

declare(strict_types=1);

/**
 * Does the server still answer after the SIGHUP restart we tell agents to use?
 *
 *   ddev exec php backend/plugins/craft-mcp/tests/Smoke/manual/sighup.php
 *
 * Manual rather than part of `composer smoke`, because it needs to signal a
 * real process, and not a unit test either: the only honest assertion runs the
 * transport's close() for real, and that closes the descriptors of whatever is
 * running it.
 *
 * Expected: "after restart: ANSWERED". A "SILENT (pipes dead)" means the
 * restart re-execs with stdin and stdout already closed, which is what
 * transport\Stdio::close() exists to prevent.
 */
$cmd = 'exec php /var/www/html/backend/vendor/stimmt/craft-mcp/bin/mcp-server';
$p = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
$pid = proc_get_status($p)['pid'];

function send($pipe, array $m): void {
    fwrite($pipe, json_encode($m) . "\n");
    fflush($pipe);
}
function readUntil($pipe, int $id, int $seconds = 20): ?array {
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $line = fgets($pipe);
        if ($line === false) {
            usleep(50000);
            continue;
        }
        $d = json_decode(trim($line), true);
        if (is_array($d) && ($d['id'] ?? null) === $id) {
            return $d;
        }
    }

    return null;
}

stream_set_blocking($pipes[1], false);
send($pipes[0], ['jsonrpc' => '2.0','id' => 1,'method' => 'initialize','params' => ['protocolVersion' => '2025-06-18','capabilities' => [],'clientInfo' => ['name' => 'sighup','version' => '1']]]);
echo 'initialize: ' . (readUntil($pipes[1], 1) ? "ok\n" : "NO RESPONSE\n");
send($pipes[0], ['jsonrpc' => '2.0','method' => 'notifications/initialized']);

posix_kill($pid, SIGHUP);
echo "sent SIGHUP to {$pid}\n";
sleep(3);

send($pipes[0], ['jsonrpc' => '2.0','id' => 2,'method' => 'initialize','params' => ['protocolVersion' => '2025-06-18','capabilities' => [],'clientInfo' => ['name' => 'sighup','version' => '1']]]);
$after = readUntil($pipes[1], 2);
echo 'after restart: ' . ($after ? 'ANSWERED, server=' . ($after['result']['serverInfo']['name'] ?? '?') . "\n" : "SILENT (pipes dead)\n");

proc_terminate($p);
