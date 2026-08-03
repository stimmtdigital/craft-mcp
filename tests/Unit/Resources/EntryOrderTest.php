<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpResourceTemplate;
use stimmt\craft\Mcp\resources\EntryResources;

// #49: the SDK matches resource templates in registration order (declaration
// order for discovered methods) with no literal-over-variable preference, so
// the literal /stats template must register before the {slug} template or it
// is unreachable. Assert over the McpResourceTemplate attributes in method
// declaration order, which is the actual load-bearing mechanism.
it('registers stats template before slug template', function () {
    $templates = [];
    foreach ((new ReflectionClass(EntryResources::class))->getMethods() as $method) {
        foreach ($method->getAttributes(McpResourceTemplate::class) as $attribute) {
            $templates[] = $attribute->newInstance()->uriTemplate;
        }
    }

    $stats = array_search('craft://entries/{section}/stats', $templates, true);
    $slug = array_search('craft://entries/{section}/{slug}', $templates, true);

    expect($stats)->not->toBeFalse()
        ->and($slug)->not->toBeFalse()
        ->and($stats)->toBeLessThan($slug);
});
