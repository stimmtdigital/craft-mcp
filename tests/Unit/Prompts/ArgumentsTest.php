<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Discovery\DocBlockParser;

// The SDK builds every PromptArgument description from the method's @param
// tags and nothing else (Discoverer reads them through DocBlockParser), so a
// prompt method without them publishes arguments with description: null and a
// client's prompt UI shows a bare name. These assertions run the same parser
// the SDK runs, not a regex over the docblock text, so they fail for exactly
// the reason prompts/list would come back empty-handed.
beforeEach(function () {
    $this->promptMethods = static function (): array {
        $methods = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/prompts/*.php') as $file) {
            $class = 'stimmt\\craft\\Mcp\\prompts\\' . basename($file, '.php');

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                $attributes = $method->getAttributes(McpPrompt::class);
                if ($attributes === []) {
                    continue;
                }

                $methods[$attributes[0]->newInstance()->name ?? $method->getName()] = $method;
            }
        }

        return $methods;
    };

    // The SDK skips parameters whose type is a class (the RequestContext a
    // handler may ask for), and publishes every builtin-typed one.
    $this->publishedParameters = static fn (ReflectionMethod $method): array => array_values(array_filter(
        $method->getParameters(),
        static function (ReflectionParameter $param): bool {
            $type = $param->getType();

            return !$type instanceof ReflectionNamedType || $type->isBuiltin();
        },
    ));
});

it('describes every prompt argument the SDK publishes', function () {
    $parser = new DocBlockParser();
    $missing = [];

    foreach (($this->promptMethods)() as $name => $method) {
        $paramTags = $parser->getParamTags($parser->parseDocBlock($method->getDocComment()));

        foreach (($this->publishedParameters)($method) as $param) {
            $tag = $paramTags['$' . $param->getName()] ?? null;

            if ($tag === null || trim((string) $tag->getDescription()) === '') {
                $missing[] = "{$name}.{$param->getName()}";
            }
        }
    }

    expect($missing)->toBe([]);
});

// Every handle argument names a HANDLE, never a display name or a numeric id,
// and the completion provider behind it offers handles. Saying so is the one
// thing the description has to get across for the argument to be usable.
it('calls handle arguments handles', function () {
    $parser = new DocBlockParser();
    $handleArguments = ['section', 'entryType', 'fieldHandle'];
    $vague = [];

    foreach (($this->promptMethods)() as $name => $method) {
        $paramTags = $parser->getParamTags($parser->parseDocBlock($method->getDocComment()));

        foreach (($this->publishedParameters)($method) as $param) {
            if (!in_array($param->getName(), $handleArguments, true)) {
                continue;
            }

            $description = strtolower(trim((string) ($paramTags['$' . $param->getName()]?->getDescription() ?? '')));
            if (!str_contains($description, 'handle')) {
                $vague[] = "{$name}.{$param->getName()}";
            }
        }
    }

    expect($vague)->toBe([]);
});

// A prompt name is a public identifier: a client stores it, a slash command
// maps to it, and renaming one is a breaking change. This list is the promise.
it('keeps the published prompt names stable', function () {
    expect(array_keys(($this->promptMethods)()))->toEqualCanonicalizing([
        'content_health_analysis',
        'content_audit',
        'debug_content_issue',
        'create_entry_guide',
        'query_entries_guide',
        'bulk_entry_operations',
        'review_pending_drafts',
        'explore_section_schema',
        'field_usage_analysis',
        'explore_content_model',
    ]);
});
