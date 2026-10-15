<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\errors\InvalidElementException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\HandleResolver;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\UserSerializer;
use stimmt\craft\Mcp\support\UserWriteRules;

/**
 * User writes (#72): add a colleague, change their name or groups, activate,
 * suspend or deactivate them.
 *
 * WHY their own category: Scope::Content admits dangerous tools only in the
 * content category, so filing these under users makes them full scope (and
 * stdio) only. Full tokens are minted by admins. Full scope skips Craft's
 * permission lookups, which is why UserWriteRules holds the line instead.
 *
 * @author stimmt.digital
 */
class UserWriteTools {
    private const string STATUS_ACTIVE = 'active';

    private const string STATUS_SUSPENDED = 'suspended';

    private const string STATUS_INACTIVE = 'inactive';

    #[McpTool(
        name: 'create_user',
        title: 'Add a user',
        description: 'Create a user account. It is created pending: the person cannot sign in until they activate it, or until set_user_status makes it active. No activation email is sent unless sendActivationEmail is true. Never creates an admin.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::USERS, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function createUser(
        #[Schema(description: 'Email address for the new account.')]
        string $email,
        #[Schema(description: 'Username. Omit to use the email address, as Craft does when useEmailAsUsername is on.')]
        ?string $username = null,
        ?string $firstName = null,
        ?string $lastName = null,
        #[Schema(type: 'array', description: 'User group handles to put the account in; list_users reports the groups users have.', items: ['type' => 'string'])]
        ?array $groups = null,
        #[Schema(description: 'Send Craft\'s activation email to the new user. Off by default: an email to a real person is a side effect to ask for.')]
        bool $sendActivationEmail = false,
        ?RequestContext $context = null,
    ): array {
        $users = Craft::$app->getUsers();
        $edition = $this->craftEdition();
        UserWriteRules::assertRoomForUser($edition->name, $users->getMaxUsers($edition), (int) User::find()->status(null)->count());
        $groupIds = $this->groupIds($groups);

        $user = new User();
        $user->email = $email;
        $user->username = $username ?? $email;
        $user->firstName = $firstName;
        $user->lastName = $lastName;
        $user->pending = true;

        if (!Craft::$app->getElements()->saveElement($user)) {
            return Response::failure(['action' => 'created', 'errors' => $user->getErrors()]);
        }

        if ($groupIds !== null) {
            $users->assignUserToGroups((int) $user->id, $groupIds);
        }

        $sent = $sendActivationEmail && $users->sendActivationEmail($user);

        return Response::success([
            'action' => 'created',
            'user' => $this->reread($user),
            'activationEmailSent' => $sent,
        ]);
    }

    #[McpTool(
        name: 'update_user',
        title: 'Update a user',
        description: 'Change a user\'s first name, last name or groups. groups is the complete set and replaces the current one. Admin accounts and the account this connection acts as are refused, and so is any change of email or password: those are made in the control panel.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::USERS, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function updateUser(
        #[Schema(description: 'Username or email address of the account.')]
        string $user,
        ?string $firstName = null,
        ?string $lastName = null,
        #[Schema(type: 'array', description: 'User group handles: the full set the account should be in, replacing the current one. Pass [] to remove it from every group.', items: ['type' => 'string'])]
        ?array $groups = null,
        ?RequestContext $context = null,
    ): array {
        $target = $this->find($user);
        $groupIds = $this->groupIds($groups);

        if ($firstName !== null) {
            $target->firstName = $firstName;
        }

        if ($lastName !== null) {
            $target->lastName = $lastName;
        }

        // Craft 5 treats a stored fullName as the source and re-derives the
        // two parts from it on save, which silently threw a new last name
        // away. Cleared, it is rebuilt from the parts instead.
        if ($firstName !== null || $lastName !== null) {
            $target->fullName = null;
        }

        if (!Craft::$app->getElements()->saveElement($target)) {
            return Response::failure(['action' => 'updated', 'errors' => $target->getErrors()]);
        }

        if ($groupIds !== null) {
            Craft::$app->getUsers()->assignUserToGroups((int) $target->id, $groupIds);
        }

        return Response::success(['action' => 'updated', 'user' => $this->reread($target)]);
    }

    #[McpTool(
        name: 'set_user_status',
        title: 'Activate or suspend a user',
        description: 'Set a user\'s account status: "active" activates a pending account or lifts a suspension, "suspended" stops the person signing in while keeping the account, "inactive" deactivates it. Admin accounts and the account this connection acts as are refused.',
        annotations: new ToolAnnotations(destructiveHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::USERS, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function setUserStatus(
        #[Schema(description: 'Username or email address of the account.')]
        string $user,
        #[Schema(description: 'The status to set.', enum: [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_INACTIVE])]
        string $status,
        ?RequestContext $context = null,
    ): array {
        $target = $this->find($user);
        $users = Craft::$app->getUsers();

        try {
            match ($status) {
                self::STATUS_ACTIVE => $this->activate($target),
                self::STATUS_SUSPENDED => $users->suspendUser($target),
                self::STATUS_INACTIVE => $users->deactivateUser($target),
                default => throw new ToolCallException("Unknown status '{$status}'; use active, suspended or inactive."),
            };
        } catch (InvalidElementException) {
            return Response::failure(['action' => 'status', 'errors' => $target->getErrors()]);
        }

        return Response::success(['action' => 'status', 'user' => $this->reread($target)]);
    }

    private function activate(User $user): void {
        if ($user->suspended) {
            Craft::$app->getUsers()->unsuspendUser($user);
        }

        if ($user->getStatus() !== User::STATUS_ACTIVE) {
            Craft::$app->getUsers()->activateUser($user);
        }
    }

    /**
     * The account a username or email names, once the rules allow changing it.
     */
    private function find(string $usernameOrEmail): User {
        $target = User::find()->status(null)->username($usernameOrEmail)->one()
            ?? User::find()->status(null)->email($usernameOrEmail)->one()
            ?? throw new ToolCallException("User '{$usernameOrEmail}' not found. Use list_users to find the username.");

        UserWriteRules::assertMayChange((string) $target->username, $target->admin, $target->id, Craft::$app->getUser()->getId());

        return $target;
    }

    /**
     * Resolved before anything is saved, so an unknown handle or a Craft
     * edition without groups refuses the whole write instead of leaving an
     * account half-made.
     *
     * @param array<int, mixed>|null $handles
     * @return int[]|null
     */
    private function groupIds(?array $handles): ?array {
        if ($handles === null) {
            return null;
        }

        $edition = $this->craftEdition();
        UserWriteRules::assertGroupsAvailable($edition->name, $edition->value >= CmsEdition::Pro->value);

        return array_map(
            static fn (mixed $handle): int => (int) (HandleResolver::userGroup((string) $handle)
                ?? throw new ToolCallException("User group '{$handle}' not found."))->id,
            array_values($handles),
        );
    }

    private function craftEdition(): CmsEdition {
        // Craft 5's own accessor (the getEdition* methods are deprecated). It
        // is a property of Craft's application trait, which the Yii
        // application type PHPStan sees for Craft::$app does not declare.
        return Craft::$app->edition; // @phpstan-ignore property.notFound
    }

    private function reread(User $user): array {
        return UserSerializer::serialize(User::find()->id($user->id)->status(null)->one() ?? $user);
    }
}
