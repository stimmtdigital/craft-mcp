<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\controllers;

use Craft;
use craft\controllers\EditUserTrait;
use craft\elements\User;
use craft\web\Controller;
use craft\web\CpScreenResponseBehavior;
use craft\web\User as CpUser;
use Override;
use stimmt\craft\Mcp\http\RecordStore;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\http\Snippet;
use stimmt\craft\Mcp\http\Token;
use stimmt\craft\Mcp\http\Tokens;
use stimmt\craft\Mcp\Mcp;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The "MCP Tokens" control panel screen: My Account self-service minting
 * (readonly and content scopes only) plus, for permitted managers, any
 * user's tokens at any scope. Reuses Craft's native edit-user chrome via
 * EditUserTrait so the screen lives inside the existing account sidebar.
 * Every authorization failure throws ForbiddenHttpException, the same as
 * Craft's own user-management actions; there is no silent redirect.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class CpTokensController extends Controller {
    use EditUserTrait;

    protected array|bool|int $allowAnonymous = false;

    #[Override]
    public function beforeAction($action): bool {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!$this->request->getIsCpRequest() || !Mcp::settings()->httpTransport) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    public function actionIndex(?int $userId = null): Response {
        $user = $this->editedUser($userId);
        $this->authorizeIndex($user);

        $variables = [
            'tokens' => $this->tokens()->listFor((int) $user->id),
            'allowedScopes' => $this->allowedScopes(),
            'user' => $user,
            'newToken' => Craft::$app->getSession()->getFlash('newToken'),
            'newTokenSnippet' => Craft::$app->getSession()->getFlash('newTokenSnippet'),
        ];

        /**
         * @var Response|CpScreenResponseBehavior $response
         * @phpstan-ignore varTag.nativeType
         */
        $response = $this->asEditUserScreen($user, 'mcp-tokens');
        $response->contentTemplate('mcp/tokens/_screen', $variables);

        return $response;
    }

    public function actionCreate(): Response {
        $this->requirePostRequest();

        $userId = (int) $this->request->getRequiredBodyParam('userId');
        $name = trim((string) $this->request->getRequiredBodyParam('name'));
        $scope = Scope::fromInput((string) $this->request->getRequiredBodyParam('scope'));

        if ($name === '') {
            throw new BadRequestHttpException('Name is required.');
        }

        $this->authorizeCreate($userId, $scope);

        ['plaintext' => $plaintext] = $this->tokens()->create($userId, $scope, $name, $this->expiresInDays());
        $this->reveal($plaintext, 'Token created.');

        return $this->redirectToPostedUrl();
    }

    public function actionRegenerate(): Response {
        $this->requirePostRequest();

        $token = $this->find((string) $this->request->getRequiredBodyParam('id'))
            ?? throw new BadRequestHttpException('Token not found.');

        // Regenerating re-issues the same token, so gate it as creating one of
        // that scope for that user (covers self/manageAll and full-needs-admin).
        $this->authorizeCreate($token->userId, $token->scope);

        ['plaintext' => $plaintext] = $this->tokens()->regenerate($token);
        $this->reveal($plaintext, 'Token regenerated.');

        return $this->redirectToPostedUrl();
    }

    public function actionRevoke(): Response {
        $this->requirePostRequest();

        $id = (string) $this->request->getRequiredBodyParam('id');
        $token = $this->find($id);
        $this->authorizeRevoke($token);

        // Revoke the resolved token by its own id, never the raw input: the
        // authorization check above and the deletion must act on the same
        // token (see Tokens::revokeById).
        if ($token?->id !== null) {
            $this->tokens()->revokeById($token->id);
        }
        $this->setSuccessFlash('Token revoked.');

        return $this->redirectToPostedUrl();
    }

    /**
     * Flash a freshly minted plaintext token and its client snippet for the
     * show-once reveal, then a success message.
     */
    private function reveal(string $plaintext, string $message): void {
        $session = Craft::$app->getSession();
        $session->setFlash('newToken', $plaintext);
        $session->setFlash('newTokenSnippet', Snippet::json($plaintext, Snippet::url()));
        $this->setSuccessFlash($message);
    }

    private function authorizeIndex(User $user): void {
        if ($user->getIsCurrent()) {
            $this->requireAnyPermission('manageOwnMcpTokens', 'manageAllMcpTokens');

            return;
        }

        $this->requirePermission('manageAllMcpTokens');
    }

    private function authorizeCreate(int $userId, Scope $scope): void {
        $currentUser = self::currentUser();

        // Full scope bypasses all read and write authorization and includes
        // code execution, so only an admin may hand it out; manageAllMcpTokens
        // alone is not enough to mint an admin-equivalent token.
        if ($scope === Scope::Full && !($currentUser->admin ?? false)) {
            throw new ForbiddenHttpException('Only admins can mint full-scope MCP tokens.');
        }

        $isSelf = $currentUser !== null && $currentUser->id === $userId;
        $selfServiceScope = $scope === Scope::ReadOnly || $scope === Scope::Content;

        if ($isSelf && $selfServiceScope) {
            $this->requireAnyPermission('manageOwnMcpTokens', 'manageAllMcpTokens');

            return;
        }

        $this->requirePermission('manageAllMcpTokens');
    }

    private function authorizeRevoke(?Token $token): void {
        $currentUser = self::currentUser();
        $isOwn = $token !== null && $currentUser !== null && $token->userId === $currentUser->id;

        if ($isOwn) {
            $this->requireAnyPermission('manageOwnMcpTokens', 'manageAllMcpTokens');

            return;
        }

        $this->requirePermission('manageAllMcpTokens');
    }

    private function requireAnyPermission(string ...$permissions): void {
        $user = $this->cpUser();
        foreach ($permissions as $permission) {
            if ($user->checkPermission($permission)) {
                return;
            }
        }

        throw new ForbiddenHttpException('User is not authorized to perform this action.');
    }

    private function cpUser(): CpUser {
        $user = Craft::$app->getUser();
        if (!$user instanceof CpUser) {
            throw new ForbiddenHttpException('User is not authorized to perform this action.');
        }

        return $user;
    }

    private function find(string $id): ?Token {
        foreach ($this->tokens()->list() as $token) {
            if ((string) $token->id === $id) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @return Scope[]
     */
    private function allowedScopes(): array {
        $scopes = [Scope::ReadOnly, Scope::Content];
        if ($this->cpUser()->checkPermission('manageAllMcpTokens')) {
            $scopes[] = Scope::Full;
        }

        return $scopes;
    }

    private function expiresInDays(): ?int {
        $value = $this->request->getBodyParam('expiresInDays');
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function tokens(): Tokens {
        return new Tokens(new RecordStore());
    }
}
