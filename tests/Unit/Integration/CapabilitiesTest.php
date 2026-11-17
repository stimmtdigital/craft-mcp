<?php

declare(strict_types=1);

use stimmt\craft\Mcp\capabilities\CommerceTools;
use stimmt\craft\Mcp\capabilities\SeomaticTools;
use stimmt\craft\Mcp\integration\Capabilities;

/**
 * A capability class missing from both lists is never registered with Craft,
 * so its tools silently do not exist; nothing errors. The lists are therefore
 * checked against the directory, not against themselves.
 */
$onDisk = static function (): array {
    $classes = array_map(
        static fn (string $file): string => 'stimmt\\craft\\Mcp\\capabilities\\' . basename($file, '.php'),
        (array) glob(dirname(__DIR__, 3) . '/src/capabilities/*.php'),
    );
    sort($classes);

    return $classes;
};

describe('Capabilities::CORE and OPTIONAL', function () use ($onDisk) {
    it('together list every capability class on disk, each exactly once', function () use ($onDisk) {
        $all = [...Capabilities::CORE, ...Capabilities::OPTIONAL];
        sort($all);

        expect($all)->toBe($onDisk());
    });

    it('list only classes that exist', function () {
        $all = [...Capabilities::CORE, ...Capabilities::OPTIONAL];

        expect(array_values(array_filter($all, static fn (string $class): bool => !class_exists($class))))->toBe([]);
    });

    // An optional class registered without its plugin makes Craft reflect
    // types that do not exist, so each one has to be able to say so.
    it('give every optional class its own availability check', function () {
        foreach (Capabilities::OPTIONAL as $class) {
            expect(method_exists($class, 'isAvailable'))->toBeTrue();
        }
    });
});

describe('Capabilities::classes()', function () {
    it('registers the core classes alone when no optional plugin is available', function () {
        expect(Capabilities::classes(static fn (string $class): bool => false))->toBe(Capabilities::CORE);
    });

    it('adds exactly the optional classes whose plugin is available', function () {
        $only = [CommerceTools::class, SeomaticTools::class];

        expect(Capabilities::classes(static fn (string $class): bool => in_array($class, $only, true)))
            ->toBe([...Capabilities::CORE, ...$only]);
    });
});
