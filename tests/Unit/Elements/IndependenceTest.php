<?php

declare(strict_types=1);

describe('elements module independence', function () {
    it('imports nothing outside craft, yii, psr/log, and itself', function () {
        $files = glob(dirname(__DIR__, 3) . '/src/elements/{,*/,*/*/}*.php', GLOB_BRACE) ?: [];
        expect($files)->not->toBeEmpty();

        $violations = [];
        foreach ($files as $file) {
            preg_match_all('/^use\s+([\w\\\\]+)/m', (string) file_get_contents($file), $m);
            foreach ($m[1] as $import) {
                $allowed = str_starts_with($import, 'craft\\')
                    || str_starts_with($import, 'yii\\')
                    || str_starts_with($import, 'Psr\\Log\\')
                    || str_starts_with($import, 'stimmt\\craft\\Mcp\\elements\\');
                if (!$allowed && str_contains($import, '\\')) {
                    $violations[] = basename($file) . ' imports ' . $import;
                }
            }
        }

        expect($violations)->toBe([]);
    });
});
