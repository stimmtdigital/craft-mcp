<?php

declare(strict_types=1);

use stimmt\craft\Mcp\console\controllers\TokensController;
use stimmt\craft\Mcp\http\Snippet;

describe('Snippet', function () {
    it('exposes json() and url() as public static methods', function () {
        expect(class_exists(Snippet::class))->toBeTrue();

        $json = new ReflectionMethod(Snippet::class, 'json');
        $url = new ReflectionMethod(Snippet::class, 'url');

        expect($json->isStatic())->toBeTrue()
            ->and($json->isPublic())->toBeTrue()
            ->and($json->getReturnType()?->getName())->toBe('string')
            ->and(array_map(fn (ReflectionParameter $p): string => $p->getName(), $json->getParameters()))
                ->toBe(['plaintext', 'url'])
            ->and($url->isStatic())->toBeTrue()
            ->and($url->isPublic())->toBeTrue()
            ->and($url->getReturnType()?->getName())->toBe('string')
            ->and($url->getParameters())->toBe([]);
    });

    it('is composed into the console controller output', function () {
        $source = (string) file_get_contents((new ReflectionClass(TokensController::class))->getFileName());

        expect($source)->toContain('Snippet::json(')
            ->and($source)->toContain('Snippet::url(');
    });
});
