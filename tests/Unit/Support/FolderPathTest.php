<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\FolderPath;

/**
 * A folder path an asset tool may create on a volume. On a local volume a
 * ".." segment would become a directory outside it, so it never gets that far.
 */
describe('FolderPath', function () {
    it('normalises to Craft\'s own folder path form', function (?string $input, string $expected) {
        expect(FolderPath::normalize($input))->toBe($expected);
    })->with([
        'root' => [null, ''],
        'empty' => ['', ''],
        'slashes trimmed, trailing one added' => ['/blog/2026/', 'blog/2026/'],
        'bare' => ['blog', 'blog/'],
    ]);

    it('refuses a segment that would leave the folder', function (string $input) {
        expect(fn () => FolderPath::normalize($input))->toThrow(ToolCallException::class, 'folder');
    })->with([['../etc'], ['blog/../../x'], ['./blog'], ['blog//x'], ['blog\\x']]);
});
