<?php

declare(strict_types=1);

use stimmt\craft\Mcp\resources\EntryResources;

it('registers stats template before slug template', function () {
    $src = (string) file_get_contents((new ReflectionClass(EntryResources::class))->getFileName());

    $statsPos = strpos($src, 'craft://entries/{section}/stats');
    $slugPos = strpos($src, 'craft://entries/{section}/{slug}');

    expect($statsPos)->toBeLessThan($slugPos)
        ->and($statsPos)->not->toBe(false)
        ->and($slugPos)->not->toBe(false);
});
