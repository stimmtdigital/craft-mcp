<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\UserWriteRules;
use stimmt\craft\Mcp\tools\UserWriteTools;

/**
 * A token that can write users is one step from minting an admin or taking
 * over an account. These rules hold on every transport and every scope,
 * full included, because full skips Craft's own permission checks.
 */
describe('UserWriteRules', function () {
    it('lets a plain account other than the caller\'s be changed', function () {
        expect(fn () => UserWriteRules::assertMayChange('jane', admin: false, targetId: 7, callerId: 1))
            ->not->toThrow(ToolCallException::class);
    });

    it('lets a write through when there is no caller to compare with (stdio)', function () {
        expect(fn () => UserWriteRules::assertMayChange('jane', admin: false, targetId: 7, callerId: null))
            ->not->toThrow(ToolCallException::class);
    });

    it('refuses an admin account', function () {
        expect(fn () => UserWriteRules::assertMayChange('root', admin: true, targetId: 7, callerId: 1))
            ->toThrow(ToolCallException::class, 'admin');
    });

    it('refuses the caller\'s own account', function () {
        expect(fn () => UserWriteRules::assertMayChange('me', admin: false, targetId: 1, callerId: 1))
            ->toThrow(ToolCallException::class, 'your own account');
    });
});

// Craft caps accounts by edition (Solo 1, Team 5) and refuses a save over the
// cap with no error at all, so without this create_user answered a bare
// success: false. Found on a Solo install.
describe('UserWriteRules edition limits', function () {
    it('lets an account be created under the cap, or with no cap', function (?int $max, int $count) {
        expect(fn () => UserWriteRules::assertRoomForUser('Team', $max, $count))->not->toThrow(ToolCallException::class);
    })->with([[5, 4], [null, 900]]);

    it('refuses an account over the cap, naming the edition and the cap', function () {
        expect(fn () => UserWriteRules::assertRoomForUser('Solo', 1, 1))
            ->toThrow(ToolCallException::class, 'Craft Solo allows 1 user account');
    });

    it('refuses user groups below Craft Pro, naming the edition', function () {
        expect(fn () => UserWriteRules::assertGroupsAvailable('Team', false))
            ->toThrow(ToolCallException::class, 'User groups need Craft Pro; this install runs Craft Team');
    });

    it('lets user groups through on Craft Pro', function () {
        expect(fn () => UserWriteRules::assertGroupsAvailable('Pro', true))->not->toThrow(ToolCallException::class);
    });
});

// The other two rules are held by what the tools do not accept: no write
// takes the admin flag, a password, or a new email address for an existing
// account (an account takeover path). Checked on the signatures, so a
// parameter added later fails here rather than in production.
it('gives no user write a parameter that sets admin, a password, or an existing account\'s email', function () {
    $offending = [];
    foreach ((new ReflectionClass(UserWriteTools::class))->getMethods() as $method) {
        $tool = $method->getAttributes(McpTool::class)[0] ?? null;
        if ($tool === null) {
            continue;
        }

        foreach ($method->getParameters() as $parameter) {
            $name = strtolower($parameter->getName());
            $forbidden = in_array($name, UserWriteRules::FORBIDDEN_PARAMETERS, true)
                || ($name === 'email' && $tool->newInstance()->name !== 'create_user');
            if ($forbidden) {
                $offending[] = "{$method->getName()}(\${$parameter->getName()})";
            }
        }
    }

    expect($offending)->toBe([]);
});
