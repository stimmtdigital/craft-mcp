<?php

declare(strict_types=1);

/**
 * Does the server still answer a client that pipelines a batch and only starts
 * reading once it has finished writing?
 *
 *   ddev exec php backend/plugins/craft-mcp/tests/Smoke/manual/wedge.php
 *
 * Manual rather than part of `composer smoke`, because it deliberately drives
 * the connection into a state a well behaved client never reaches, and because
 * the failure it looks for is a hang: nothing errors, nothing is logged, both
 * processes simply stop.
 *
 * The shape of the script IS the deadlock. The server answers the first call
 * with more than the 64 KiB the pipe holds, blocks inside its own fwrite, and
 * while blocked there stops reading stdin. The rest of the batch then piles up
 * behind it until the client's write blocks too, and neither side can move.
 * The filler requests exist only to reach that second half: what wedges the
 * client is the volume of bytes queued behind an unread pipe, not the number of
 * calls, so they are padded to reach it in tens of messages rather than
 * thousands. Padding sits inside the JSON object, so every line on the wire is
 * a valid request the server answers normally.
 *
 * The client's own stdin write is non-blocking here purely so this script can
 * report the wedge instead of joining it. A real client blocks in that fwrite
 * and never returns, which is what NO WRITE PROGRESS stands for.
 *
 * Expected: "ANSWERED". A "WEDGED" means the server stopped reading stdin while
 * its own answer was still going out, which is what the buffered output in
 * transport\Stdio exists to prevent.
 */
const SERVER = 'exec php /var/www/html/backend/vendor/stimmt/craft-mcp/bin/mcp-server';

/**
 * How long the client may fail to write a single byte before this is a wedge
 * rather than a slow tool. A tool call is handled synchronously, so the server
 * legitimately stops reading stdin for as long as the call takes; this has to
 * clear the slowest one by a wide margin to accuse it of anything.
 */
const STALL_SECONDS = 30;

/**
 * How long the whole batch has to come back once the client starts reading.
 */
const READ_SECONDS = 120;

/**
 * Bytes of request the client pushes without reading. Six times the 64 KiB a
 * pipe holds, so the client's write is certain to run out of room while the
 * server is blocked, on any buffer size a kernel is likely to hand us.
 */
const FILLER_BYTES = 393216;

/**
 * Size of one filler request. Large enough that reaching FILLER_BYTES costs
 * tens of round trips on a healthy server rather than thousands.
 */
const FILLER_SIZE = 8192;

/**
 * The call whose answer overflows the pipe: list_entries at the default page
 * size returns about 118 KB, which is ordinary use rather than a stress case.
 */
const LARGE_CALLS = 2;

$pipes = [];
$process = proc_open(SERVER, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    fwrite(STDERR, "Could not start the server.\n");

    exit(2);
}

[$toServer, $fromServer, $diagnostics] = $pipes;
stream_set_blocking($fromServer, false);
stream_set_blocking($diagnostics, false);

$exitCode = 2;

try {
    $exitCode = run($toServer, $fromServer, $diagnostics);
} finally {
    proc_terminate($process);
    proc_close($process);
}

exit($exitCode);

function run(mixed $toServer, mixed $fromServer, mixed $diagnostics): int {
    if (!handshake($toServer, $fromServer)) {
        report($diagnostics);

        return 2;
    }

    $batch = batch();
    fwrite(STDOUT, 'Pushing ' . count($batch) . ' requests (' . bytes(payload($batch)) . ") without reading.\n");

    // From here on the client stops behaving like one. A real client is inside
    // a blocking fwrite for as long as this lasts, and stays there; going
    // non-blocking is what lets this script notice that and say so.
    stream_set_blocking($toServer, false);

    $started = microtime(true);
    $unwritten = push($toServer, payload($batch));
    if ($unwritten > 0) {
        fwrite(STDOUT, 'WEDGED: no write progress for ' . STALL_SECONDS . 's with ' . bytes($unwritten) . " still queued.\n");
        fwrite(STDOUT, "The server stopped reading stdin while its own answer was going out.\n");
        report($diagnostics);

        return 1;
    }

    fwrite(STDOUT, sprintf("All requests written in %.2fs. Reading.\n", microtime(true) - $started));
    $missing = collect($fromServer, array_keys($batch));

    if ($missing !== []) {
        fwrite(STDOUT, 'INCOMPLETE: ' . count($missing) . ' of ' . count($batch) . ' responses never arrived (ids ' . implode(', ', array_slice($missing, 0, 10)) . ").\n");
        report($diagnostics);

        return 1;
    }

    fwrite(STDOUT, sprintf("ANSWERED: all %d responses arrived in %.2fs.\n", count($batch), microtime(true) - $started));

    return 0;
}

/**
 * Opens the session the way any client does, reading each answer as it comes.
 * Nothing here is under test; it only has to succeed before the batch means
 * anything.
 */
function handshake(mixed $toServer, mixed $fromServer): bool {
    send($toServer, encode(1, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => new stdClass(),
        'clientInfo' => ['name' => 'wedge', 'version' => '1'],
    ]));

    if (await($fromServer, 1, 60) === null) {
        fwrite(STDOUT, "UNREACHABLE: the server never answered initialize.\n");

        return false;
    }

    send($toServer, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR));

    return true;
}

/**
 * The batch, keyed by request id: the large calls first so the server is
 * already blocked writing when the filler arrives behind it.
 *
 * @return array<int, string>
 */
function batch(): array {
    $requests = [];
    $id = 100;

    for ($i = 0; $i < LARGE_CALLS; $i++) {
        $requests[$id] = encode($id, 'tools/call', ['name' => 'list_entries', 'arguments' => ['limit' => 100]]);
        $id++;
    }

    while (strlen(payload($requests)) < FILLER_BYTES) {
        $requests[$id] = filler($id);
        $id++;
    }

    return $requests;
}

/**
 * One filler request: a ping padded to FILLER_SIZE with whitespace inside the
 * object, which JSON permits and the server decodes to the same message.
 */
function filler(int $id): string {
    $ping = '{"jsonrpc":"2.0","id":' . $id . ',"method":"ping","params":{}';
    $padding = FILLER_SIZE - strlen($ping) - 1;

    return $ping . str_repeat(' ', max($padding, 0)) . '}';
}

/**
 * @param array<int, string> $requests
 */
function payload(array $requests): string {
    return implode("\n", $requests) . "\n";
}

/**
 * Pushes the batch without ever reading stdout, and returns how much of it
 * never went out. Anything above zero is the deadlock: the server is not
 * draining stdin, so a real client would still be inside this fwrite.
 */
function push(mixed $toServer, string $queue): int {
    $stalledSince = null;

    while ($queue !== '') {
        $written = fwrite($toServer, substr($queue, 0, 65536));
        if ($written === false) {
            return strlen($queue);
        }

        if ($written > 0) {
            $queue = substr($queue, $written);
            $stalledSince = null;

            continue;
        }

        $stalledSince ??= microtime(true);
        if (microtime(true) - $stalledSince > STALL_SECONDS) {
            return strlen($queue);
        }

        $read = null;
        $write = [$toServer];
        $except = null;
        stream_select($read, $write, $except, 1);
    }

    return 0;
}

/**
 * Reads until every id has been answered, and returns the ones that never were.
 *
 * @param array<int, int> $ids
 * @return list<int>
 */
function collect(mixed $fromServer, array $ids): array {
    $outstanding = array_fill_keys($ids, true);
    $deadline = microtime(true) + READ_SECONDS;

    while ($outstanding !== [] && microtime(true) < $deadline) {
        $line = nextLine($fromServer, $deadline);
        if ($line === null) {
            break;
        }

        $message = json_decode($line, true);
        $id = is_array($message) ? ($message['id'] ?? null) : null;
        if (is_int($id)) {
            unset($outstanding[$id]);
        }
    }

    return array_keys($outstanding);
}

function nextLine(mixed $fromServer, float $deadline): ?string {
    $buffer = '';

    while (microtime(true) < $deadline) {
        $chunk = fgets($fromServer);
        if ($chunk === false) {
            if (feof($fromServer)) {
                return null;
            }

            $read = [$fromServer];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 0, 50000);

            continue;
        }

        $buffer .= $chunk;
        if (str_ends_with($buffer, "\n")) {
            return trim($buffer);
        }
    }

    return null;
}

function await(mixed $fromServer, int $id, int $seconds): ?string {
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $line = nextLine($fromServer, $deadline);
        if ($line === null) {
            return null;
        }

        $message = json_decode($line, true);
        if (is_array($message) && ($message['id'] ?? null) === $id) {
            return $line;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $params
 */
function encode(int $id, string $method, array $params): string {
    return json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function send(mixed $toServer, string $line): void {
    fwrite($toServer, $line . "\n");
    fflush($toServer);
}

function report(mixed $diagnostics): void {
    $stderr = trim((string) stream_get_contents($diagnostics));
    if ($stderr === '') {
        return;
    }

    fwrite(STDOUT, "Server stderr:\n" . $stderr . "\n");
}

function bytes(string|int $value): string {
    $size = is_string($value) ? strlen($value) : $value;

    return number_format($size / 1024, 1) . ' KiB';
}
