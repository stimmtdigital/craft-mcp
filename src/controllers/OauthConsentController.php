<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\controllers;

use Craft;
use craft\web\Controller;
use craft\web\CpScreenResponseBehavior;
use craft\web\User as CpUser;
use Override;
use stimmt\craft\Mcp\http\oauth\Authorizer;
use stimmt\craft\Mcp\http\oauth\AuthorizeRequest;
use stimmt\craft\Mcp\http\oauth\OAuthConfig;
use stimmt\craft\Mcp\http\oauth\OAuthError;
use stimmt\craft\Mcp\http\oauth\RecordOAuthStore;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\support\ScopePolicy;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The consent screen an app sends the user to when connecting.
 *
 * WHY in the control panel: Craft already knows how to make a guest log in
 * and come back, the screen gets the control panel's look and session, and
 * the user approving is exactly the control panel user whose permissions the
 * token will carry.
 *
 * The request parameters travel through the form as hidden fields and are
 * checked again on submit, so nothing about the request is trusted from the
 * moment the screen was rendered.
 *
 * @author stimmt.digital
 */
final class OauthConsentController extends Controller {
    private const array PARAMS = ['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'];

    protected array|bool|int $allowAnonymous = false;

    #[Override]
    public function beforeAction($action): bool {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $settings = Mcp::settings();
        if (!$this->request->getIsCpRequest() || !$settings->httpTransport || !$settings->oauth) {
            throw new NotFoundHttpException();
        }

        // The consent screen must never render inside another site's frame,
        // where a click could be stolen.
        $this->response->getHeaders()->set('X-Frame-Options', 'DENY');
        $this->response->getHeaders()->set('Content-Security-Policy', "frame-ancestors 'none'");

        return true;
    }

    public function actionIndex(): Response {
        $params = $this->params($this->request->getQueryParams());
        $authorizer = $this->authorizer();

        try {
            $client = $authorizer->identify($params);
        } catch (OAuthError $error) {
            return $this->screen('mcp/oauth/_error', ['message' => $error->getMessage()]);
        }

        try {
            $request = $authorizer->validate($client, $params);
        } catch (OAuthError $error) {
            return $this->redirect($authorizer->errorRedirect($client, $params, $error));
        }

        $allowed = $this->allowedScopes();

        return $this->screen('mcp/oauth/_consent', [
            'request' => $request,
            'params' => $params,
            'scopes' => $allowed,
            'selected' => $this->preselect($request, $allowed),
            'user' => Craft::$app->getUser()->getIdentity(),
        ]);
    }

    public function actionApprove(): Response {
        $this->requirePostRequest();
        [$authorizer, $request] = $this->postedRequest();

        $scope = Scope::tryFrom((string) $this->request->getBodyParam('scope'));
        if ($scope === null || !in_array($scope, $this->allowedScopes(), true)) {
            throw new BadRequestHttpException(Craft::t('mcp', 'Choose a scope you are allowed to grant.'));
        }

        return $this->redirect($authorizer->approve($request, (int) Craft::$app->getUser()->getId(), $scope));
    }

    public function actionDeny(): Response {
        $this->requirePostRequest();
        [$authorizer, $request] = $this->postedRequest();

        return $this->redirect($authorizer->deny($request));
    }

    /**
     * @return array{0: Authorizer, 1: AuthorizeRequest}
     */
    private function postedRequest(): array {
        $params = $this->params((array) $this->request->getBodyParam('oauth', []));
        $authorizer = $this->authorizer();

        try {
            return [$authorizer, $authorizer->validate($authorizer->identify($params), $params)];
        } catch (OAuthError $error) {
            throw new BadRequestHttpException($error->getMessage(), 0, $error);
        }
    }

    /**
     * Connecting an app hands it a token of the user's own, so it takes the
     * same permission as minting one on the token screen.
     *
     * @return Scope[]
     */
    private function allowedScopes(): array {
        $user = Craft::$app->getUser();
        if (!$user instanceof CpUser || !$user->checkPermission('manageOwnMcpTokens') && !$user->checkPermission('manageAllMcpTokens')) {
            return [];
        }

        return ScopePolicy::allowed($user->getIdentity(), Mcp::settings()->disabledScopes);
    }

    /**
     * Content when it was asked for and is allowed, since that is what an
     * editing assistant needs; otherwise the narrowest scope on offer. Never
     * Full by default: that is a choice the user makes, not one made for them.
     *
     * @param Scope[] $allowed
     */
    private function preselect(AuthorizeRequest $request, array $allowed): ?Scope {
        $offered = array_values(array_filter($allowed, static fn (Scope $scope): bool => in_array($scope, $request->requestedScopes, true)));

        if (in_array(Scope::Content, $offered, true)) {
            return Scope::Content;
        }

        return $offered[0] ?? $allowed[0] ?? null;
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<string, string>
     */
    private function params(array $input): array {
        $params = [];
        foreach (self::PARAMS as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $params[$key] = $input[$key];
            }
        }

        return $params;
    }

    private function authorizer(): Authorizer {
        return new Authorizer(new RecordOAuthStore(), OAuthConfig::current());
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function screen(string $template, array $variables): Response {
        /**
         * @var Response|CpScreenResponseBehavior $response
         * @phpstan-ignore varTag.nativeType
         */
        $response = $this->asCpScreen();
        $response->title(Craft::t('mcp', 'Connect an app'));
        $response->contentTemplate($template, $variables);

        return $response;
    }
}
