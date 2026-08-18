<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Icon;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\events\RegisterPromptsEvent;
use stimmt\craft\Mcp\events\RegisterResourcesEvent;
use stimmt\craft\Mcp\events\RegisterToolsEvent;
use stimmt\craft\Mcp\models\PromptDefinition;
use stimmt\craft\Mcp\models\ResourceDefinition;
use stimmt\craft\Mcp\models\ToolDefinition;

/**
 * The definitions carried name, description and our own policy fields and
 * nothing else, so a third party's read-only tool reached clients under the
 * conservative destructive defaults and its title, icons, _meta and output
 * schema were dropped between the attribute and the builder.
 */
final class MetadataTools {
    #[McpTool(
        name: 'metadata_tool',
        title: 'Metadata Tool',
        description: 'declares everything the SDK attribute allows',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
        icons: [new Icon('https://example.test/tool.png')],
        meta: ['vendor' => 'example'],
        outputSchema: ['type' => 'object'],
    )]
    public function run(): array {
        return [];
    }
}

final class MetadataBareTools {
    #[McpTool(name: 'bare_tool', description: 'declares nothing beyond name and description')]
    public function run(): array {
        return [];
    }
}

final class MetadataPrompts {
    #[McpPrompt(
        name: 'metadata_prompt',
        title: 'Metadata Prompt',
        description: 'declares everything the SDK attribute allows',
        icons: [new Icon('https://example.test/prompt.png')],
        meta: ['vendor' => 'example'],
    )]
    public function run(): array {
        return [];
    }
}

final class MetadataResources {
    #[McpResource(
        uri: 'craft://fixtures/metadata',
        name: 'metadata_resource',
        title: 'Metadata Resource',
        description: 'declares everything the SDK attribute allows',
        mimeType: 'application/json',
        size: 1024,
        annotations: new Annotations(audience: [Role::Assistant], priority: 1.0),
        icons: [new Icon('https://example.test/resource.png')],
        meta: ['vendor' => 'example'],
    )]
    public function read(): string {
        return '';
    }

    #[McpResourceTemplate(
        uriTemplate: 'craft://fixtures/metadata/{id}',
        name: 'metadata_template',
        title: 'Metadata Template',
        description: 'declares everything the SDK template attribute allows',
        mimeType: 'application/json',
        annotations: new Annotations(priority: 0.5),
        meta: ['vendor' => 'example'],
    )]
    public function readOne(string $id): string {
        return $id;
    }
}

it('carries every #[McpTool] field onto the tool definition', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(MetadataTools::class, 'test');

    $definition = $event->getDefinitions()['metadata_tool'];

    expect($definition->title)->toBe('Metadata Tool')
        ->and($definition->annotations?->readOnlyHint)->toBeTrue()
        ->and($definition->annotations?->openWorldHint)->toBeFalse()
        ->and($definition->icons)->toHaveCount(1)
        ->and($definition->meta)->toBe(['vendor' => 'example'])
        ->and($definition->outputSchema)->toBe(['type' => 'object']);
});

it('leaves the added tool fields null when the attribute declares none', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(MetadataBareTools::class, 'test');

    $definition = $event->getDefinitions()['bare_tool'];

    expect($definition->title)->toBeNull()
        ->and($definition->annotations)->toBeNull()
        ->and($definition->icons)->toBeNull()
        ->and($definition->meta)->toBeNull()
        ->and($definition->outputSchema)->toBeNull();
});

it('carries every #[McpPrompt] field onto the prompt definition', function (): void {
    $event = new RegisterPromptsEvent();
    $event->addPrompt(MetadataPrompts::class, 'test');

    $definition = $event->getDefinitions()['metadata_prompt'];

    expect($definition->title)->toBe('Metadata Prompt')
        ->and($definition->icons)->toHaveCount(1)
        ->and($definition->meta)->toBe(['vendor' => 'example']);
});

it('carries every #[McpResource] field onto the resource definition', function (): void {
    $event = new RegisterResourcesEvent();
    $event->addResource(MetadataResources::class, 'test');

    $definition = $event->getDefinitions()['craft://fixtures/metadata'];

    expect($definition->title)->toBe('Metadata Resource')
        ->and($definition->size)->toBe(1024)
        ->and($definition->annotations?->priority)->toBe(1.0)
        ->and($definition->annotations?->audience)->toBe([Role::Assistant])
        ->and($definition->icons)->toHaveCount(1)
        ->and($definition->meta)->toBe(['vendor' => 'example']);
});

it('carries every #[McpResourceTemplate] field onto the template definition', function (): void {
    $event = new RegisterResourcesEvent();
    $event->addResource(MetadataResources::class, 'test');

    $definition = $event->getDefinitions()['metadata_template'];

    // #[McpResourceTemplate] declares neither size nor icons, so both stay null.
    expect($definition->title)->toBe('Metadata Template')
        ->and($definition->annotations?->priority)->toBe(0.5)
        ->and($definition->meta)->toBe(['vendor' => 'example'])
        ->and($definition->size)->toBeNull()
        ->and($definition->icons)->toBeNull();
});

it('keeps fromArray tolerant of every added key being absent', function (): void {
    $tool = ToolDefinition::fromArray(['name' => 'bare']);
    $prompt = PromptDefinition::fromArray(['name' => 'bare']);
    $resource = ResourceDefinition::fromArray(['uri' => 'craft://bare']);

    expect($tool->outputSchema)->toBeNull()
        ->and($tool->annotations)->toBeNull()
        ->and($prompt->icons)->toBeNull()
        ->and($resource->size)->toBeNull()
        ->and($resource->annotations)->toBeNull();
});
