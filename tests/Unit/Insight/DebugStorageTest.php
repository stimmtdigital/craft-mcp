<?php

declare(strict_types=1);

use stimmt\craft\Mcp\insight\DebugStorage;

/**
 * Proves the unserialize hardening from the outside: a planted object in a
 * debug file must come back inert, its magic methods never run.
 */
final class DebugStorageTripwire {
    public static bool $woken = false;

    public function __wakeup(): void {
        self::$woken = true;
    }
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/craft-mcp-debug-' . bin2hex(random_bytes(4));
    mkdir($this->dir);
    DebugStorageTripwire::$woken = false;
});

afterEach(function () {
    foreach ((array) glob($this->dir . '/*') as $path) {
        is_dir((string) $path) ? rmdir((string) $path) : unlink((string) $path);
    }
    rmdir($this->dir);
});

describe('DebugStorage', function () {
    it('says there is no index when the module never wrote one', function () {
        $storage = new DebugStorage($this->dir);

        expect($storage->hasIndex())->toBeFalse()
            ->and($storage->manifest())->toBeNull();
    });

    it('reads the index the module writes', function () {
        file_put_contents($this->dir . '/index.data', serialize(['abc1' => ['url' => '/x', 'processingTime' => 0.1]]));

        expect((new DebugStorage($this->dir))->manifest())->toBe(['abc1' => ['url' => '/x', 'processingTime' => 0.1]]);
    });

    // The outer handler turns any warning that escapes into a failure, so
    // this proves the read stays quiet rather than only that it returns null.
    it('answers a truncated index with null, without a warning escaping', function () {
        file_put_contents($this->dir . '/index.data', substr(serialize(['abc1' => ['url' => '/x']]), 0, 12));
        $storage = new DebugStorage($this->dir);
        set_error_handler(static fn (int $level, string $message): never => throw new ErrorException($message, 0, $level));

        try {
            $manifest = $storage->manifest();
        } finally {
            restore_error_handler();
        }

        expect($storage->hasIndex())->toBeTrue()
            ->and($manifest)->toBeNull();
    });

    it('reads one panel of a request file, which is itself serialized', function () {
        $panels = ['db' => serialize(['messages' => [['SELECT 1', 0x50]]]), 'summary' => []];
        file_put_contents($this->dir . '/abc1.data', serialize($panels));

        expect((new DebugStorage($this->dir))->panel('abc1', 'db'))->toBe(['messages' => [['SELECT 1', 0x50]]]);
    });

    it('answers a pruned request or a missing panel with null', function () {
        file_put_contents($this->dir . '/abc1.data', serialize(['summary' => []]));
        $storage = new DebugStorage($this->dir);

        expect($storage->panel('gone1', 'db'))->toBeNull()
            ->and($storage->panel('abc1', 'db'))->toBeNull();
    });

    // A real file sits where the traversal points, so only the tag check
    // stands between the caller and it.
    it('refuses a tag that walks the path', function () {
        mkdir($this->dir . '/sub');
        file_put_contents($this->dir . '/abc1.data', serialize(['db' => serialize(['messages' => []])]));

        expect((new DebugStorage($this->dir))->panel('sub/../abc1', 'db'))->toBeNull();
    });

    it('never instantiates an object planted in the index', function () {
        file_put_contents($this->dir . '/index.data', serialize(['abc1' => ['payload' => new DebugStorageTripwire()]]));
        DebugStorageTripwire::$woken = false;

        $manifest = (new DebugStorage($this->dir))->manifest();

        expect(DebugStorageTripwire::$woken)->toBeFalse()
            ->and($manifest['abc1']['payload'])->toBeInstanceOf(__PHP_Incomplete_Class::class);
    });

    it('never instantiates an object planted in a panel', function () {
        $panels = ['db' => serialize(['messages' => [new DebugStorageTripwire()]])];
        file_put_contents($this->dir . '/abc1.data', serialize($panels));
        DebugStorageTripwire::$woken = false;

        (new DebugStorage($this->dir))->panel('abc1', 'db');

        expect(DebugStorageTripwire::$woken)->toBeFalse();
    });
});
