<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/RecordingLogger.php';

use stimmt\craft\Mcp\Tests\Fixtures\RecordingLogger;
use stimmt\craft\Mcp\transport\Inbox;

describe('Inbox', function () {
    // stdin as listen() leaves it: non-blocking, which is what makes fgets()
    // hand back half a message in the first place.
    $stdin = function (): array {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        expect($pair)->toBeArray();
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return $pair;
    };

    it('returns a message that arrived whole', function () use ($stdin) {
        [$input, $client] = $stdin();
        $inbox = new Inbox($input);

        fwrite($client, '{"id":1}' . "\n");

        expect($inbox->next())->toBe('{"id":1}');
    });

    it('assembles a message that arrived in pieces', function () use ($stdin) {
        [$input, $client] = $stdin();
        $inbox = new Inbox($input);

        // The regression: the SDK answered the first half with a syntax error
        // and the second half with another, and the request was never answered.
        fwrite($client, '{"jsonrpc":"2.0","id":7,');
        expect($inbox->next())->toBeNull();

        fwrite($client, '"method":"ping"}' . "\n");
        expect($inbox->next())->toBe('{"jsonrpc":"2.0","id":7,"method":"ping"}');
    });

    it('keeps messages separate when several arrive at once', function () use ($stdin) {
        [$input, $client] = $stdin();
        $inbox = new Inbox($input);

        fwrite($client, "{\"id\":1}\n{\"id\":2}\n{\"id\":3}\n");

        expect($inbox->next())->toBe('{"id":1}')
            ->and($inbox->next())->toBe('{"id":2}')
            ->and($inbox->next())->toBe('{"id":3}')
            ->and($inbox->next())->toBeNull();
    });

    it('reports nothing when the stream is empty', function () use ($stdin) {
        [$input] = $stdin();

        expect((new Inbox($input))->next())->toBeNull();
    });

    it('steps over blank lines rather than waking on them', function () use ($stdin) {
        [$input, $client] = $stdin();
        $inbox = new Inbox($input);

        fwrite($client, "\n\n{\"id\":9}\n");

        expect($inbox->next())->toBe('{"id":9}');
    });

    it('refuses a message with no end and picks up at the next one', function () {
        // A seekable stream rather than a socket here: pushing 16 MiB through a
        // socket a test also has to drain costs seconds and proves nothing the
        // pieces above have not already proven. What matters is the ceiling.
        $input = fopen('php://memory', 'r+b');
        fwrite($input, str_repeat('n', 17 * 1048576));
        rewind($input);

        $logger = new RecordingLogger();
        $inbox = new Inbox($input, $logger);

        expect($inbox->next())->toBeNull()
            ->and($logger->levels())->toContain('error');

        // The tail of the refused message is thrown away as far as its newline,
        // so the next real message is read as a message and not as its ending.
        $resume = ftell($input);
        fwrite($input, "abandoned tail\n" . '{"id":11}' . "\n");
        fseek($input, $resume);

        expect($inbox->next())->toBe('{"id":11}');
    });
});
