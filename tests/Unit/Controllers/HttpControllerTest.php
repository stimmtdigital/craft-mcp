<?php

declare(strict_types=1);

use stimmt\craft\Mcp\controllers\HttpController;

// HttpController extends craft\web\Controller, which needs a booted
// yii\base\Module to construct. Craft is never booted in these tests, so
// assertions are structural, mirroring tests/Unit/Controllers/CpTokensControllerTest.php.
describe('HttpController authentication', function () {
    // Without this, disabledScopes would only govern who may press the mint
    // button: every token issued before the scope was closed would keep
    // working, so switching a scope off in an environment would not actually
    // close it. Rejecting here is what makes the setting a guarantee about
    // what can reach the server, matching disabledTools.
    it('rejects a token whose scope is disabled in this environment', function () {
        $source = (string) file_get_contents((new ReflectionClass(HttpController::class))->getFileName());

        expect($source)->toContain('Mcp::isScopeEnabled($token->scope)');
    });

    // The scope check has to sit inside authenticate(), on the path every
    // request takes, rather than anywhere a single action could bypass.
    it('checks the scope during authenticate, before the request is served', function () {
        $source = (string) file_get_contents((new ReflectionClass(HttpController::class))->getFileName());

        $authenticate = strpos($source, 'private function authenticate');
        $serve = strpos($source, 'private function serve');

        expect(strpos($source, 'Mcp::isScopeEnabled($token->scope)'))
            ->toBeGreaterThan($authenticate)
            ->toBeLessThan($serve);
    });

    // A disabled scope must be indistinguishable from a bad token to the
    // caller: authenticate() returning null is what produces the standard 401,
    // rather than leaking that the scope exists but is switched off.
    it('fails closed by returning null, the same as an unknown token', function () {
        $source = (string) file_get_contents((new ReflectionClass(HttpController::class))->getFileName());

        $check = strpos($source, 'Mcp::isScopeEnabled($token->scope)');
        $tail = substr($source, $check, 120);

        expect($tail)->toContain('return null;');
    });
});
