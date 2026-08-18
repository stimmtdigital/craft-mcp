<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;

// MCP has a vocabulary for "read this one first": Annotations carries an
// audience and a priority where 1.0 means the data is effectively required.
// The content-writing contract is the one resource that earns it, and saying so
// in the protocol beats hoping a client reads the description.
beforeEach(function () {
    $this->annotated = static function (): array {
        $found = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/resources/*.php') as $file) {
            $class = 'stimmt\\craft\\Mcp\\resources\\' . basename($file, '.php');

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                foreach ([McpResource::class, McpResourceTemplate::class] as $attribute) {
                    foreach ($method->getAttributes($attribute) as $declared) {
                        $instance = $declared->newInstance();
                        $found[$instance->name ?? $method->getName()] = $instance->annotations;
                    }
                }
            }
        }

        return $found;
    };
});

it('annotates the content-writing guide as required reading for the model', function () {
    $annotations = ($this->annotated)()['content-writing-guide'] ?? null;

    expect($annotations)->toBeInstanceOf(Annotations::class)
        ->and($annotations->audience)->toBe([Role::Assistant])
        ->and($annotations->priority)->toBe(1.0);
});

// Annotations are a claim, not decoration. An empty set says nothing while
// still shipping an `annotations` key, so either a resource carries real
// information or it carries no annotations at all.
it('never ships an empty annotation set', function () {
    $empty = [];

    foreach (($this->annotated)() as $name => $annotations) {
        if ($annotations instanceof Annotations && $annotations->audience === null && $annotations->priority === null) {
            $empty[] = $name;
        }
    }

    expect($empty)->toBe([]);
});

// A resource name and uri are public identifiers: a client stores them and a
// rename is a breaking change for anyone who bookmarked one.
it('keeps the published resource names stable', function () {
    expect(array_keys(($this->annotated)()))->toEqualCanonicalizing([
        'general-config',
        'routes-config',
        'sites-config',
        'volumes-config',
        'installed-plugins',
        'section-entries',
        'section-stats',
        'entry-by-slug',
        'content-writing-guide',
        'all-sections',
        'all-fields',
        'section-schema',
        'field-details',
    ]);
});
