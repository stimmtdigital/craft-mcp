<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\utilities;

use Craft;
use craft\base\Utility;
use craft\elements\User;
use craft\web\User as CpUser;
use craft\web\View;
use Override;
use RuntimeException;
use stimmt\craft\Mcp\http\RecordStore;
use stimmt\craft\Mcp\http\Token;
use stimmt\craft\Mcp\http\Tokens as TokenStore;

/**
 * Utilities panel audit of every MCP HTTP token across every user. Craft
 * gates access via the `utility:mcp-tokens` permission it derives from id(),
 * but that is a separate grant from managing tokens, so contentHtml() also
 * requires `manageAllMcpTokens` before disclosing any cross-user token data.
 * web\Cp additionally only registers this class when httpTransport is enabled
 * on a CP request, so the utility never appears when the transport is off.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Tokens extends Utility {
    #[Override]
    public static function displayName(): string {
        return 'MCP Tokens';
    }

    #[Override]
    public static function id(): string {
        return 'mcp-tokens';
    }

    #[Override]
    public static function icon(): string {
        return 'key';
    }

    #[Override]
    public static function contentHtml(): string {
        $user = Craft::$app->getUser();
        if (!$user instanceof CpUser || !$user->checkPermission('manageAllMcpTokens')) {
            return '';
        }

        $tokens = (new TokenStore(new RecordStore()))->list();

        return self::view()->renderTemplate('mcp/tokens/_utility', [
            'tokens' => $tokens,
            'userLabels' => self::userLabels($tokens),
            'revokeAction' => 'mcp/cp-tokens/revoke',
            'redirect' => Craft::$app->getRequest()->getPathInfo(),
        ]);
    }

    /**
     * Craft::$app->getView() is typed against yii\web\Application on the
     * base Yii facade, which only knows about yii\base\View; narrow it to
     * the real Craft view component that renderTemplate() lives on.
     */
    private static function view(): View {
        $view = Craft::$app->getView();
        if (!$view instanceof View) {
            throw new RuntimeException('The Craft view component is not available.');
        }

        return $view;
    }

    /**
     * @param Token[] $tokens
     * @return array<int, string>
     */
    private static function userLabels(array $tokens): array {
        $userIds = array_values(array_unique(array_map(
            static fn (Token $token): int => $token->userId,
            $tokens,
        )));

        if ($userIds === []) {
            return [];
        }

        $labels = [];
        foreach (User::find()->id($userIds)->status(null)->all() as $user) {
            if ($user->id !== null) {
                $labels[$user->id] = $user->getUiLabel();
            }
        }

        return $labels;
    }
}
