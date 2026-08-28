<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/RecordingLogger.php';

use stimmt\craft\Mcp\Tests\Fixtures\RecordingLogger;
use stimmt\craft\Mcp\transport\Outbox;

describe('Outbox', function () {
    // A connected pair stands in for the pipe between server and client: one
    // end is handed to the outbox as stdout, the other is the client, read from
    // only when a test says so. Both are non-blocking so a test can never be
    // the thing that hangs.
    $pair = function (): array {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        expect($pair)->toBeArray();
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return $pair;
    };

    // A stdin that stays open and says nothing. Its far end has to be held for
    // the length of the test: dropped, the socket reports EOF, which the outbox
    // reads as a request waiting and gives way to on every drain.
    $open = [];
    $quiet = function () use ($pair, &$open) {
        [$stdin, $peer] = $pair();
        $open[] = $peer;

        return $stdin;
    };

    // How much a socket takes before it refuses, measured rather than assumed:
    // it is 8 KiB on macOS and 208 KiB on Linux, and a test that guesses either
    // way is a test that passes on one machine only.
    $capacity = function () use ($pair): int {
        [$socket, $peer] = $pair();
        $taken = 0;

        while (($written = fwrite($socket, str_repeat('c', 65536))) > 0) {
            $taken += $written;
        }

        fclose($peer);

        return $taken;
    };

    $readAll = function ($client): string {
        $received = '';
        while (($chunk = fread($client, 65536)) !== false && $chunk !== '') {
            $received .= $chunk;
        }

        return $received;
    };

    it('accepts a write whole and returns at once, however large', function () use ($pair, $quiet) {
        [$stdout] = $pair();
        $outbox = new Outbox($stdout, $quiet());

        $frame = str_repeat('x', 1048576) . "\n";

        // The SDK discards this return value, which is exactly why the resource
        // it writes to must never take less than it was given.
        expect(fwrite($outbox->resource(), $frame))->toBe(strlen($frame));
    });

    it('delivers frames in the order they were produced', function () use ($pair, $quiet, $readAll) {
        [$stdout, $client] = $pair();
        $outbox = new Outbox($stdout, $quiet());

        foreach (['first', 'second', 'third'] as $frame) {
            fwrite($outbox->resource(), $frame . "\n");
        }

        expect($outbox->drain())->toBeTrue()
            ->and($readAll($client))->toBe("first\nsecond\nthird\n");
    });

    it('holds what the client cannot take yet and delivers it once it reads', function () use ($pair, $quiet, $capacity, $readAll) {
        [$stdout, $client] = $pair();
        $outbox = new Outbox($stdout, $quiet());

        // One frame the socket cannot swallow whole, which is the case that
        // used to block the SDK inside fwrite and stop the listen loop.
        $frame = str_repeat('abcdefgh', intdiv($capacity() + 8192, 8)) . "\n";
        fwrite($outbox->resource(), $frame);

        expect($outbox->drain())->toBeTrue();

        $received = $readAll($client);
        expect(strlen($received))->toBeLessThan(strlen($frame));

        for ($round = 0; $round < 64 && strlen($received) < strlen($frame); $round++) {
            expect($outbox->drain())->toBeTrue();
            $received .= $readAll($client);
        }

        expect($received)->toBe($frame);
    });

    it('reports a client that has stopped reading once the backlog passes its ceiling', function () use ($pair, $quiet) {
        [$stdout, $client] = $pair();
        $logger = new RecordingLogger();
        $outbox = new Outbox($stdout, $quiet(), $logger);

        // The ceiling is 64 MiB, and a single frame is never refused, so the
        // only way past it is a backlog nobody is draining.
        $megabyte = str_repeat('y', 1048576);
        for ($frames = 0; $frames < 65; $frames++) {
            fwrite($outbox->resource(), $megabyte);
        }

        expect($outbox->drain())->toBeFalse()
            ->and($logger->levels())->toContain('error')
            ->and(is_resource($client))->toBeTrue();
    });

    it('gives up on a client that never reads rather than holding the process open', function () use ($pair, $quiet) {
        [$stdout, $client] = $pair();
        $logger = new RecordingLogger();
        $outbox = new Outbox($stdout, $quiet(), $logger);

        fwrite($outbox->resource(), str_repeat('z', 4194304) . "\n");

        $started = microtime(true);
        $outbox->flush(0.5);

        expect(microtime(true) - $started)->toBeLessThan(5.0)
            ->and($logger->levels())->toContain('warning')
            ->and(is_resource($client))->toBeTrue();
    });

    it('delivers the backlog at shutdown when the client is still reading', function () use ($pair, $quiet, $readAll) {
        [$stdout, $client] = $pair();
        $logger = new RecordingLogger();
        $outbox = new Outbox($stdout, $quiet(), $logger);

        $frame = str_repeat('q', 4096) . "\n";
        fwrite($outbox->resource(), $frame);

        $outbox->flush(1.0);

        expect($readAll($client))->toBe($frame)
            ->and($logger->records)->toBe([]);
    });

    it('closes the real stdout and leaves the buffer to the parent transport', function () use ($pair, $quiet) {
        [$stdout] = $pair();
        $outbox = new Outbox($stdout, $quiet());
        $buffer = $outbox->resource();

        $outbox->close();

        expect(is_resource($stdout))->toBeFalse()
            ->and(is_resource($buffer))->toBeTrue();
    });
});
