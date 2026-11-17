<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\insight\ComposerLock;

$lock = static fn (array $lock): string => (string) json_encode($lock);

describe('ComposerLock::packages()', function () use ($lock) {
    $fixture = [
        'packages' => [
            ['name' => 'craftcms/cms', 'version' => '5.12.1'],
            ['name' => 'guzzlehttp/guzzle', 'version' => '7.9.2'],
        ],
        'packages-dev' => [
            ['name' => 'pestphp/pest', 'version' => 'v4.1.0'],
        ],
    ];

    it('reads the production packages only by default', function () use ($lock, $fixture) {
        expect(ComposerLock::packages($lock($fixture), false))->toBe([
            'craftcms/cms' => '5.12.1',
            'guzzlehttp/guzzle' => '7.9.2',
        ]);
    });

    it('adds packages-dev when asked, sorted by name', function () use ($lock, $fixture) {
        expect(ComposerLock::packages($lock($fixture), true))->toBe([
            'craftcms/cms' => '5.12.1',
            'guzzlehttp/guzzle' => '7.9.2',
            'pestphp/pest' => 'v4.1.0',
        ]);
    });

    it('leaves out a row with no name or version rather than guessing', function () use ($lock) {
        $json = $lock(['packages' => [['name' => 'a/b'], ['version' => '1.0.0'], 'junk', ['name' => 'c/d', 'version' => '2.0.0']]]);

        expect(ComposerLock::packages($json, false))->toBe(['c/d' => '2.0.0']);
    });

    it('answers an empty lock with no packages', function () use ($lock) {
        expect(ComposerLock::packages($lock(['packages' => []]), true))->toBe([]);
    });

    it('refuses a lock that is not JSON, saying how to repair it', function () {
        expect(fn () => ComposerLock::packages('{not json', false))
            ->toThrow(ToolCallException::class, 'composer.lock is not valid JSON');
    });

    it('refuses a lock that is JSON but not an object', function () {
        expect(fn () => ComposerLock::packages('"text"', false))
            ->toThrow(ToolCallException::class, 'does not hold a JSON object');
    });
});
