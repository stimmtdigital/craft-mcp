<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\db\GlobalSetQuery;
use craft\elements\db\UserQuery;
use craft\elements\GlobalSet;
use craft\elements\User;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\Authorization;

/**
 * v2 has no per-request enforceFor()/reset(): the acting user is whoever
 * Craft's MCP server logged in (the token's user over HTTP, `--user` over
 * stdio), read live from Craft's user component. These tests stand in a user
 * component whose identity they control, so they observe the real decision
 * rather than the source text.
 */
$withIdentity = static function (?object $identity): void {
    Craft::$app = new class ($identity) {
        public function __construct(private readonly ?object $identity) {
        }

        public function getUser(): object {
            return new class ($this->identity) {
                public function __construct(private readonly ?object $identity) {
                }

                public function getIdentity(): ?object {
                    return $this->identity;
                }
            };
        }
    };
};

/**
 * A user whose permissions are a fixed list, so `can()` needs no database.
 * The element constructor is skipped on purpose: Element::init() asks the
 * application for the primary site and the mailer's IDN settings, neither of
 * which this check touches.
 *
 * @param list<string> $permissions
 */
$user = static fn (array $permissions = [], int $id = 7): User => new class ($id, $permissions) extends User {
    /** @param list<string> $permissions */
    public function __construct(int $id, private readonly array $permissions) {
        $this->id = $id;
        $this->username = 'editor';
    }

    public function can(string $permission): bool {
        return in_array($permission, $this->permissions, true);
    }
};

describe('Authorization', function () use ($withIdentity, $user) {
    beforeEach(function () {
        $this->previousApp = Craft::$app;
    });

    afterEach(function () {
        Craft::$app = $this->previousApp;
    });

    it('is not enforced when nobody is logged in', function () use ($withIdentity) {
        $withIdentity(null);

        expect(Authorization::enforced())->toBeFalse();
    });

    it('is enforced for the user Craft\'s MCP server acts as', function () use ($withIdentity, $user) {
        $withIdentity($user());

        expect(Authorization::enforced())->toBeTrue();
    });

    // An identity that is not a Craft user cannot be asked about Craft
    // permissions, so it must not switch enforcement on half-way.
    it('is not enforced for an identity that is not a Craft user', function () use ($withIdentity) {
        $withIdentity(new stdClass());

        expect(Authorization::enforced())->toBeFalse();
    });

    it('lets a raw permission check through when nobody is logged in', function () use ($withIdentity) {
        $withIdentity(null);

        Authorization::assertCan('utility:system-report', 'read the system report');

        expect(Authorization::enforced())->toBeFalse();
    });

    it('lets a raw permission check through for a user who has the permission', function () use ($withIdentity, $user) {
        $withIdentity($user(['utility:system-report']));

        Authorization::assertCan('utility:system-report', 'read the system report');

        expect(Authorization::enforced())->toBeTrue();
    });

    it('refuses a raw permission the acting user lacks, naming the user and the permission', function () use ($withIdentity, $user) {
        $withIdentity($user());

        expect(fn () => Authorization::assertCan('utility:system-report', 'read the system report'))
            ->toThrow(ToolCallException::class, "The connected user 'editor' is not allowed to read the system report (missing Craft permission 'utility:system-report'");
    });

    it('leaves a list query alone when nobody is logged in, whatever its type', function () use ($withIdentity) {
        $withIdentity(null);
        $query = new GlobalSetQuery(GlobalSet::class);

        Authorization::scopeQuery($query);

        expect($query->id)->toBeNull();
    });

    // A new element-list tool must not ship without a deliberate scoping
    // rule: an unknown query type refuses rather than listing everything.
    it('refuses to scope a list query type it has no rule for', function () use ($withIdentity, $user) {
        $withIdentity($user());

        expect(fn () => Authorization::scopeQuery(new GlobalSetQuery(GlobalSet::class)))
            ->toThrow(ToolCallException::class, 'no view-scoping rule for ' . GlobalSetQuery::class);
    });

    it('limits a user without viewUsers to themselves', function () use ($withIdentity, $user) {
        $withIdentity($user([], 42));
        $query = new UserQuery(User::class);

        Authorization::scopeQuery($query);

        expect($query->id)->toBe(42);
    });

    it('leaves the user list open to a user with viewUsers', function () use ($withIdentity, $user) {
        $withIdentity($user(['viewUsers'], 42));
        $query = new UserQuery(User::class);

        Authorization::scopeQuery($query);

        expect($query->id)->toBeNull();
    });

    it('exposes the element assertion seams', function (string $method) {
        expect(method_exists(Authorization::class, $method))->toBeTrue();
    })->with([['assertCanSave'], ['assertCanPublish'], ['assertCanDelete'], ['assertCanDuplicate'], ['assertCanView'], ['assertCan'], ['scopeQuery']]);

    // Publishing mirrors the control panel's apply-draft gate: the draft AND
    // its canonical entry must be savable. Duplicating needs view plus the
    // duplicate-as-draft permission.
    it('publishes and duplicates through Craft\'s own compound checks', function () {
        $source = (string) file_get_contents((string) (new ReflectionClass(Authorization::class))->getFileName());

        expect($source)->toContain('canSaveCanonical')
            ->and($source)->toContain('canDuplicateAsDraft');
    });

    // scopeQuery must INTERSECT the caller's own section/volume/group filter
    // with the viewable set, never overwrite it (which would widen an explicit
    // narrow filter up to the user's full ceiling).
    it('scopeQuery narrows the caller filter by intersection, not overwrite', function () {
        $source = (string) file_get_contents((string) (new ReflectionClass(Authorization::class))->getFileName());

        expect($source)->toContain('array_intersect(')
            ->and($source)->toContain('$query->sectionId')
            ->and($source)->toContain('$query->volumeId')
            ->and($source)->toContain('$query->groupId');
    });
});
