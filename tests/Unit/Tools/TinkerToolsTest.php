<?php

declare(strict_types=1);

use stimmt\craft\Mcp\tools\TinkerTools;

describe('TinkerTools blocked patterns', function () {
    it('blocks shell exec calls', function () {
        $result = (new TinkerTools())->tinker("exec('ls');");

        expect($result->text)->toContain('SecurityError');
    });

    it('blocks unbounded output buffer teardown loops', function (string $code) {
        $result = (new TinkerTools())->tinker($code);

        expect($result->text)->toContain('SecurityError');
    })->with([
        'spaced' => 'while (ob_get_level() > 0) { ob_end_clean(); }',
        'compact' => 'while(ob_get_level()){ob_end_clean();}',
        'negated comparison' => 'while (ob_get_level() !== 0) { ob_end_clean(); }',
    ]);
});
