<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';

use craft\web\Request as CraftRequest;
use craft\web\Response as CraftResponse;
use Nyholm\Psr7\Response as Psr7Response;
use stimmt\craft\Mcp\http\Bridge;

describe('Bridge', function () {
    it('converts a craft request to a psr-7 server request', function () {
        // CraftRequest::init() resolves the current site and Craft-facade
        // aliases, none of which Bridge reads; constructing without it keeps
        // this test from depending on a booted Craft::$app.
        $request = (new ReflectionClass(CraftRequest::class))->newInstanceWithoutConstructor();
        $request->setRawBody('{"jsonrpc":"2.0"}');
        $request->setUrl('/mcp');
        $request->setHostInfo('https://cms.craft.dev');
        $request->getHeaders()->set('Content-Type', 'application/json');
        $request->getHeaders()->set('Mcp-Session-Id', 'abc');
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $psr7 = (new Bridge())->toPsr7($request);

        expect($psr7->getMethod())->toBe('POST')
            ->and((string) $psr7->getUri())->toBe('https://cms.craft.dev/mcp')
            ->and($psr7->getHeaderLine('Mcp-Session-Id'))->toBe('abc')
            ->and((string) $psr7->getBody())->toBe('{"jsonrpc":"2.0"}');
    });

    it('applies a psr-7 response onto a craft response', function () {
        $psr7 = new Psr7Response(202, ['Mcp-Session-Id' => 'abc', 'Content-Type' => 'application/json'], '{"ok":true}');
        // CraftResponse::init() (via yii\web\Response) reads Yii::$app->charset,
        // which Bridge never touches; skip it for the same reason as above.
        $craft = (new ReflectionClass(CraftResponse::class))->newInstanceWithoutConstructor();

        (new Bridge())->apply($psr7, $craft);

        expect($craft->statusCode)->toBe(202)
            ->and($craft->getHeaders()->get('Mcp-Session-Id'))->toBe('abc')
            ->and($craft->content)->toBe('{"ok":true}')
            ->and($craft->format)->toBe(CraftResponse::FORMAT_RAW);
    });
});
