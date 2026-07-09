<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\User;
use craft\helpers\Console;
use InvalidArgumentException;
use Override;
use stimmt\craft\Mcp\http\RecordStore;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\http\Tokens;
use stimmt\craft\Mcp\Mcp;
use yii\console\ExitCode;

/**
 * Manage HTTP transport bearer tokens: create, list, revoke.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class TokensController extends Controller {
    /** @var string|null Email or username of the Craft user the token acts as. */
    public ?string $user = null;

    /** @var string Token scope: readonly, content, or full. */
    public string $scope = 'content';

    /** @var string|null Display name for the token (shown in list/revoke). */
    public ?string $name = null;

    /** @var int|null Days until the token expires; omit for no expiry. */
    public ?int $expires = null;

    #[Override]
    public function options($actionID): array {
        $options = match ($actionID) {
            'create' => ['user', 'scope', 'name', 'expires'],
            default => [],
        };

        return array_merge(parent::options($actionID), $options);
    }

    /**
     * Create a token and print it once, with a Claude Desktop snippet.
     */
    public function actionCreate(): int {
        $identity = (string) $this->user;
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($identity);
        if ($user === null || $user->id === null) {
            $this->stderr("No user found for '{$identity}'. Pass --user=<email or username>.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        try {
            $scope = Scope::fromInput($this->scope);
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $name = $this->name ?? ($user->username . ' token');
        ['plaintext' => $plaintext] = (new Tokens(new RecordStore()))
            ->create((int) $user->id, $scope, $name, $this->expires);

        $url = rtrim(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '', '/') . '/' . Mcp::settings()->httpPath;

        $this->stdout("Token created (shown once, store it now):\n\n  {$plaintext}\n\n", Console::FG_GREEN);
        $this->stdout("Claude Desktop config (claude_desktop_config.json):\n");
        $this->stdout(<<<JSON
  {
    "mcpServers": {
      "craft-cms": {
        "url": "{$url}",
        "headers": { "Authorization": "Bearer {$plaintext}" }
      }
    }
  }

JSON);

        return ExitCode::OK;
    }

    /**
     * List tokens: id, name, user, scope, expiry, last used.
     */
    public function actionList(): int {
        $tokens = (new Tokens(new RecordStore()))->list();
        if ($tokens === []) {
            $this->stdout("No tokens.\n");

            return ExitCode::OK;
        }

        foreach ($tokens as $token) {
            $identity = Craft::$app->getUsers()->getUserById($token->userId);
            $user = $identity instanceof User ? $identity->username : ('#' . $token->userId);
            $expiry = $token->expiryDate?->format('Y-m-d') ?? 'never';
            $used = $token->lastUsedAt?->format('Y-m-d H:i') ?? 'never';
            $this->stdout("[{$token->id}] {$token->name}  user={$user}  scope={$token->scope->value}  expires={$expiry}  lastUsed={$used}\n");
        }

        return ExitCode::OK;
    }

    /**
     * Revoke a token by name or id.
     */
    public function actionRevoke(string $nameOrId): int {
        if ((new Tokens(new RecordStore()))->revoke($nameOrId)) {
            $this->stdout("Revoked '{$nameOrId}'.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stderr("No token named '{$nameOrId}'.\n", Console::FG_RED);

        return ExitCode::USAGE;
    }
}
