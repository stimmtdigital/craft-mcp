<?php

declare(strict_types=1);

use stimmt\craft\Mcp\Mcp;

// Craft is never booted in these tests: registering the endpoint wires
// yii\base\Event handlers against the UrlManager and reads live request
// state, so the assertions are structural. They pin the routing contract
// that makes the endpoint reachable on control-panel-on-root installs
// (empty cpTrigger): both rule sets registered, no site-request gate, and
// the plugin-handle collision guarded instead of silently broken.
describe('HTTP endpoint registration', function () {
    $source = static function (string $method): string {
        $reflection = new ReflectionMethod(Mcp::class, $method);
        $file = file($reflection->getFileName());

        return implode('', array_slice($file, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    };

    it('registers the endpoint without a site-request gate', function () use ($source) {
        $body = $source('registerHttpEndpoint');

        expect($body)->toContain('EVENT_REGISTER_SITE_URL_RULES')
            ->and($body)->toContain('registerCpEndpointRule')
            ->and($body)->not->toContain('getIsSiteRequest');
    });

    it('registers a CP url rule for control-panel-on-root installs', function () use ($source) {
        $body = $source('registerCpEndpointRule');

        expect($body)->toContain('EVENT_REGISTER_CP_URL_RULES')
            ->and($body)->toContain('cpTrigger');
    });

    it('refuses the CP rule when httpPath collides with the plugin handle', function () use ($source) {
        $body = $source('registerCpEndpointRule');

        expect($body)->toContain('$settings->httpPath === $this->id')
            ->and($body)->toContain('Craft::warning');
    });
});
