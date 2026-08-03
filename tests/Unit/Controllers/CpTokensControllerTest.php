<?php

declare(strict_types=1);

use craft\controllers\EditUserTrait;
use craft\web\Controller;
use stimmt\craft\Mcp\controllers\CpTokensController;

// CpTokensController extends craft\web\Controller, which requires a booted
// yii\base\Module to construct. Craft and Twig are never booted in these
// tests, so assertions are structural: reflection over the class shape and
// source-string checks over the authorization contract, mirroring
// tests/Unit/Web/CpTest.php.
describe('CpTokensController', function () {
    it('extends craft\web\Controller and uses EditUserTrait', function () {
        $class = new ReflectionClass(CpTokensController::class);

        expect($class->isSubclassOf(Controller::class))->toBeTrue()
            ->and(array_key_exists(EditUserTrait::class, $class->getTraits()))->toBeTrue();
    });

    it('is not accessible anonymously', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($source)->toContain('$allowAnonymous = false');
    });

    it('gates every action behind a CP request and httpTransport, 404 otherwise', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($source)->toContain('getIsCpRequest()')
            ->and($source)->toContain('httpTransport')
            ->and($source)->toContain('NotFoundHttpException');
    });

    it('exposes actionIndex, actionCreate, actionRegenerate, and actionRevoke', function () {
        $class = new ReflectionClass(CpTokensController::class);

        expect($class->hasMethod('actionIndex'))->toBeTrue()
            ->and($class->hasMethod('actionCreate'))->toBeTrue()
            ->and($class->hasMethod('actionRegenerate'))->toBeTrue()
            ->and($class->hasMethod('actionRevoke'))->toBeTrue();
    });

    it('renders the account screen via asEditUserScreen with the mcp-tokens screen id', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($source)->toContain('asEditUserScreen($user, \'mcp-tokens\')')
            ->and($source)->toContain('contentTemplate(\'mcp/tokens/_screen\'');
    });

    it('requires manageOwnMcpTokens or manageAllMcpTokens to view your own screen, else manageAllMcpTokens', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($source)->toContain('function authorizeIndex')
            ->and($source)->toContain('getIsCurrent()')
            ->and($source)->toContain("requireAnyPermission('manageOwnMcpTokens', 'manageAllMcpTokens')")
            ->and($source)->toContain("requirePermission('manageAllMcpTokens')");
    });

    it('restricts self-service minting to readonly and content scopes', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($source)->toContain('function authorizeCreate')
            ->and($source)->toContain('Scope::ReadOnly')
            ->and($source)->toContain('Scope::Content')
            // Self-service accepts manageOwn, but manageAll is a superset and
            // must satisfy the self path too (coherent authorization matrix).
            ->and($source)->toContain("requireAnyPermission('manageOwnMcpTokens', 'manageAllMcpTokens')");
    });

    // Regenerating re-issues the same token, so it must carry the same
    // create-capability gate (self/manageAll and full-needs-admin).
    it('gates regenerate as re-creating the token', function () {
        $method = new ReflectionMethod(CpTokensController::class, 'actionRegenerate');
        $body = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($method->isPublic())->toBeTrue()
            ->and($body)->toContain('->regenerate(')
            ->and($body)->toContain('$this->authorizeCreate($token->userId, $token->scope)');
    });

    // Full scope is code execution and bypasses all authorization, so only an
    // admin may mint it; manageAllMcpTokens alone must not confer it.
    it('requires admin to mint a full-scope token', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($source)->toContain('Scope::Full')
            ->and($source)->toContain('->admin')
            ->and($source)->toContain('Only admins can mint full-scope');
    });

    it('every authorization path can throw ForbiddenHttpException, never a silent redirect', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($source)->toContain('ForbiddenHttpException');
    });

    it('checks ownership before revoking so someone else\'s token requires manageAllMcpTokens', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($source)->toContain('function authorizeRevoke')
            ->and($source)->toContain('$token->userId === $currentUser->id');
    });

    // #48: the dropdown (allowedScopes()) used to offer Full whenever the
    // current user held manageAllMcpTokens, while authorizeCreate() only
    // accepted Full for real admins. A manager without the admin flag could
    // see Full listed and still get a 403 on submit. Both call sites must
    // now consult the same admin predicate so they can never disagree again.
    it('gates Full via one shared admin predicate consumed by both the dropdown and creation', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($source)->toContain('function fullScopeAllowed')
            ->and($source)->toContain('$this->fullScopeAllowed(self::currentUser())')
            ->and($source)->toContain('$this->fullScopeAllowed($currentUser)')
            ->and($source)->not->toContain("checkPermission('manageAllMcpTokens')");
    });

    // #48: disabledScopes is a per-environment guardrail that beats every
    // permission, admin included. The dropdown drops a disabled scope
    // entirely (reporter's stated preference over a submit-time 403), and
    // creation rejects it server-side too, in case a request bypasses the
    // dropdown. Both consult the same Scope::isDisabled() predicate.
    it('drops disabled scopes from the dropdown and rejects them at create time for everyone', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());

        expect($source)->toContain('function authorizeScopeEnabled')
            ->and($source)->toContain('->isDisabled(Mcp::settings()->disabledScopes)')
            ->and($source)->toContain('scope is disabled on this install')
            ->and($source)->toContain('!$scope->isDisabled($disabled)');
    });

    // #48: existing tokens of a now-disabled scope must keep regenerating,
    // so disabledScopes only gates *new* minting: actionCreate() calls
    // authorizeScopeEnabled(), actionRegenerate() must not. Assert against
    // each method body rather than whole-file occurrence counts, so comments
    // naming the method cannot mask or fake a call site.
    it('does not gate regenerate on disabledScopes', function () {
        $source = (string) file_get_contents((new ReflectionClass(CpTokensController::class))->getFileName());
        $body = static function (string $method) use ($source): string {
            $start = strpos($source, 'function ' . $method);
            expect($start)->not->toBeFalse();
            $end = strpos($source, 'public function', $start + 1);

            return substr($source, $start, ($end === false ? strlen($source) : $end) - $start);
        };

        expect($body('actionCreate'))->toContain('authorizeScopeEnabled(')
            ->and($body('actionRegenerate'))->not->toContain('authorizeScopeEnabled(');
    });
});
