<?php

declare(strict_types=1);

use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\http\Tokens;
use stimmt\craft\Mcp\Tests\Fixtures\InMemoryTokenStore;

describe('Tokens', function () {
    it('creates a prefixed 44-char plaintext and stores only its hash', function () {
        $store = new InMemoryTokenStore();
        ['token' => $token, 'plaintext' => $plaintext] = (new Tokens($store))
            ->create(7, Scope::Content, 'Anna Desktop');

        expect($plaintext)->toStartWith('mcp_')
            ->and(strlen($plaintext))->toBe(44)
            ->and($token->id)->not->toBeNull()
            ->and($store->hashes())->toBe([Tokens::hash($plaintext)])
            ->and($store->hashes())->not->toContain($plaintext);
    });

    it('authenticates the plaintext round trip and rejects garbage', function () {
        $store = new InMemoryTokenStore();
        $tokens = new Tokens($store);
        ['plaintext' => $plaintext] = $tokens->create(7, Scope::ReadOnly, 'Reader');

        expect($tokens->authenticate($plaintext)?->scope)->toBe(Scope::ReadOnly)
            ->and($tokens->authenticate('mcp_wrong'))->toBeNull();
    });

    it('rejects expired tokens', function () {
        $store = new InMemoryTokenStore();
        $now = new DateTimeImmutable('2026-07-09 12:00:00');
        $tokens = new Tokens($store, fn (): DateTimeImmutable => $now);
        ['plaintext' => $plaintext] = $tokens->create(7, Scope::Full, 'Short lived', expiresInDays: 1);

        $later = new Tokens($store, fn (): DateTimeImmutable => $now->modify('+2 days'));

        expect($tokens->authenticate($plaintext))->not->toBeNull()
            ->and($later->authenticate($plaintext))->toBeNull();
    });

    it('stamps lastUsedAt at most once per hour', function () {
        $store = new InMemoryTokenStore();
        $now = new DateTimeImmutable('2026-07-09 12:00:00');
        $tokens = new Tokens($store, fn (): DateTimeImmutable => $now);
        ['plaintext' => $plaintext] = $tokens->create(7, Scope::Content, 'Anna');

        $tokens->authenticate($plaintext);
        expect($store->touches)->toBe(1);
        $tokens->authenticate($plaintext);
        expect($store->touches)->toBe(1);

        $hourLater = new Tokens($store, fn (): DateTimeImmutable => $now->modify('+61 minutes'));
        $hourLater->authenticate($plaintext);
        expect($store->touches)->toBe(2);
    });

    it('revokes by name or id and lists what remains', function () {
        $store = new InMemoryTokenStore();
        $tokens = new Tokens($store);
        $tokens->create(7, Scope::Content, 'Anna');
        ['token' => $b] = $tokens->create(8, Scope::ReadOnly, 'Bert');

        expect($tokens->revoke('Anna'))->toBeTrue()
            ->and($tokens->revoke((string) $b->id))->toBeTrue()
            ->and($tokens->revoke('Nobody'))->toBeFalse()
            ->and($tokens->list())->toBe([]);
    });

    it('revokes by id only, never matching another token whose name equals that id', function () {
        $store = new InMemoryTokenStore();
        $tokens = new Tokens($store);
        // Decoy created first, so it holds the smaller id and would win an
        // ascending name-or-id scan; its name is the string form of the id we revoke.
        $tokens->create(8, Scope::ReadOnly, '2');
        ['token' => $victim] = $tokens->create(7, Scope::Content, 'Real');

        expect($tokens->revokeById((int) $victim->id))->toBeTrue()
            ->and(array_map(fn ($token) => $token->name, $tokens->list()))->toBe(['2']);
    });

    it('lists only the tokens belonging to the given user', function () {
        $store = new InMemoryTokenStore();
        $tokens = new Tokens($store);
        $tokens->create(7, Scope::Content, 'Anna');
        ['token' => $bert] = $tokens->create(8, Scope::ReadOnly, 'Bert');
        $tokens->create(7, Scope::Full, 'Anna Two');

        $forSeven = $tokens->listFor(7);

        expect(array_map(fn ($token) => $token->name, $forSeven))->toBe(['Anna', 'Anna Two'])
            ->and($tokens->listFor(8))->toBe([$bert])
            ->and($tokens->listFor(999))->toBe([]);
    });
});
