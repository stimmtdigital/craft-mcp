<?php

declare(strict_types=1);

use Mcp\Server\Session\SessionStoreInterface;
use stimmt\craft\Mcp\http\DbSessionStore;

it('implements the SDK session store contract', function () {
    expect(class_implements(DbSessionStore::class))->toHaveKey(SessionStoreInterface::class);
});

it('defaults the ttl to one hour', function () {
    $parameter = new ReflectionParameter([DbSessionStore::class, '__construct'], 'ttl');

    expect($parameter->getDefaultValue())->toBe(3600);
});
