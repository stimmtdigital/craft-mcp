<?php

declare(strict_types=1);

use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\Warning;

describe('Context', function () {
    it('carries the site and collects warnings in order', function () {
        $context = new Context('en');
        $context->warn(new Warning('a', 'a.0', [], 'first'));
        $context->warn(new Warning('b', 'b.1', [], 'second'));

        expect($context->site)->toBe('en')
            ->and($context->warnings())->toHaveCount(2)
            ->and($context->warnings()[0]->message)->toBe('first');
    });

    it('defaults to no site', function () {
        expect((new Context())->site)->toBeNull();
    });
});
