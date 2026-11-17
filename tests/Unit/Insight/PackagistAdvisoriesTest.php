<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\insight\PackagistAdvisories;

/**
 * No test here reaches the network: the parsing goes through an injected
 * fetch, and the HTTP adapter through Guzzle's MockHandler, whose history
 * records what would have been sent.
 */
describe('PackagistAdvisories::forPackages()', function () {
    it('returns the advisories object keyed by package', function () {
        $fetch = static fn (array $names): string => (string) json_encode(['advisories' => ['a/b' => [['advisoryId' => 'X']]]]);

        expect((new PackagistAdvisories($fetch(...)))->forPackages(['a/b']))
            ->toBe(['a/b' => [['advisoryId' => 'X']]]);
    });

    it('does not call out for an empty package list', function () {
        $called = false;
        $fetch = static function (array $names) use (&$called): string {
            $called = true;

            return '{}';
        };

        expect((new PackagistAdvisories($fetch(...)))->forPackages([]))->toBe([])
            ->and($called)->toBeFalse();
    });

    it('refuses a body that is not JSON instead of answering empty', function () {
        $packagist = new PackagistAdvisories(static fn (array $names): string => '<html>busy</html>');

        expect(fn () => $packagist->forPackages(['a/b']))->toThrow(ToolCallException::class, 'not JSON');
    });

    it('refuses JSON without an advisories object instead of answering empty', function () {
        $packagist = new PackagistAdvisories(static fn (array $names): string => '{"error":"rate limited"}');

        expect(fn () => $packagist->forPackages(['a/b']))->toThrow(ToolCallException::class, 'without an "advisories" object');
    });
});

describe('PackagistAdvisories::overHttp()', function () {
    $client = static function (array $queue, array &$history): Client {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack]);
    };

    it('posts only the package names to the advisory endpoint, with a timeout', function () use ($client) {
        $history = [];
        $http = $client([new Response(200, [], '{"advisories":{}}')], $history);

        PackagistAdvisories::overHttp($http)->forPackages(['craftcms/cms', 'guzzlehttp/guzzle']);

        $request = $history[0]['request'];
        parse_str((string) $request->getBody(), $form);

        expect($request->getMethod())->toBe('POST')
            ->and((string) $request->getUri())->toBe(PackagistAdvisories::ENDPOINT)
            ->and($form)->toBe(['packages' => ['craftcms/cms', 'guzzlehttp/guzzle']])
            ->and($history[0]['options']['timeout'])->toBe(PackagistAdvisories::TIMEOUT_SECONDS);
    });

    it('turns a network failure into a refusal that names the cause, without proxy credentials', function () use ($client) {
        $history = [];
        $failure = new ConnectException(
            'cURL error 7: Failed to connect via http://user:s3cret@proxy.local:3128',
            new Request('POST', PackagistAdvisories::ENDPOINT),
        );
        $packagist = PackagistAdvisories::overHttp($client([$failure], $history));

        try {
            $packagist->forPackages(['a/b']);
            $message = null;
        } catch (ToolCallException $exception) {
            $message = $exception->getMessage();
        }

        expect($message)->toContain('Could not get advisories from packagist.org: cURL error 7')
            ->and($message)->toContain('http://***@proxy.local:3128')
            ->and($message)->not->toContain('s3cret');
    });
});
