<?php

declare(strict_types=1);

use craft\elements\db\UserQuery;
use craft\elements\User;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use stimmt\craft\Mcp\tools\UserTools;

/**
 * What list_users tells a caller its status parameter accepts, against what
 * the guard actually accepts.
 *
 * The description listed five values and the guard took six: credentialed
 * (active or pending) is a status Craft's UserQuery understands and the
 * element does not list, so HandleResolver adds it by hand, the refusal
 * advertises it, and it works. The doc was wrong, not the guard, and a
 * documented surface that undersells itself is a capability nobody calls.
 */
describe('list_users status', function () {
    it('documents every value the guard accepts', function () {
        $description = (string) (new SchemaGenerator(new DocBlockParser()))
            ->generate(new ReflectionMethod(UserTools::class, 'listUsers'))['properties']['status']['description'];

        // Read off Craft's own constants rather than retyped, so a value it
        // renames cannot leave a description quietly saying the old word.
        $accepted = [
            User::STATUS_ACTIVE,
            User::STATUS_PENDING,
            User::STATUS_SUSPENDED,
            User::STATUS_LOCKED,
            User::STATUS_INACTIVE,
            UserQuery::STATUS_CREDENTIALED,
        ];

        $undocumented = array_values(array_filter(
            $accepted,
            static fn (string $status): bool => !str_contains($description, $status),
        ));

        expect($undocumented)->toBe([]);
    });

    it('says what credentialed means, since no tool lists that vocabulary', function () {
        $description = (string) (new SchemaGenerator(new DocBlockParser()))
            ->generate(new ReflectionMethod(UserTools::class, 'listUsers'))['properties']['status']['description'];

        expect($description)->toContain('active or pending');
    });
});
