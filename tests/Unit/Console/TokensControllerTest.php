<?php

declare(strict_types=1);

use stimmt\craft\Mcp\console\controllers\TokensController;

// TokensController extends craft\console\Controller, which needs a booted
// yii\base\Module to construct. Craft is never booted in these tests, so
// assertions are structural, mirroring tests/Unit/Controllers/CpTokensControllerTest.php.
describe('console TokensController', function () {
    it('exposes create, list, and revoke actions', function (string $method) {
        expect((new ReflectionClass(TokensController::class))->hasMethod($method))->toBeTrue();
    })->with([['actionCreate'], ['actionList'], ['actionRevoke']]);

    // The console runs as whoever has shell access, with no CP permission
    // check to lean on. If disabledScopes were enforced only in the control
    // panel, `php craft mcp/tokens/create --scope=full` would walk past it and
    // the setting would be advisory rather than a guardrail.
    it('refuses to mint a scope disabled in config', function () {
        $source = (string) file_get_contents((new ReflectionClass(TokensController::class))->getFileName());

        expect($source)->toContain('Mcp::isScopeEnabled($scope)')
            ->and($source)->toContain('disabledScopes');
    });

    it('refuses before creating the token, not after', function () {
        $source = (string) file_get_contents((new ReflectionClass(TokensController::class))->getFileName());

        expect(strpos($source, 'Mcp::isScopeEnabled($scope)'))
            ->toBeLessThan(strpos($source, '->create((int) $user->id'));
    });
});
