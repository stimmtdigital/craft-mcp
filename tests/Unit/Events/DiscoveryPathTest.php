<?php

declare(strict_types=1);

use stimmt\craft\Mcp\events\RegisterToolsEvent;
use stimmt\craft\Mcp\Tests\Unit\Events\Fixtures\DiscoveredTools;

/**
 * addDiscoveryPath() was documented for plugin authors, stored by the registry,
 * and read by nothing: a plugin that registered a directory got no tools and no
 * error. It now registers every class it finds exactly as addTool() would, so
 * the path is a shorthand for a list of classes rather than a second
 * registration mechanism the rest of the pipeline knows nothing about.
 */
beforeEach(function (): void {
    $this->event = new RegisterToolsEvent();
    $this->fixturePath = __DIR__;
});

it('registers the tool classes it finds under the path', function (): void {
    $this->event->addDiscoveryPath($this->fixturePath, ['Fixtures'], 'test-plugin');

    $definitions = $this->event->getDefinitions();

    expect($definitions)->toHaveKey('found_by_path')
        ->and($definitions['found_by_path']->class)->toBe(DiscoveredTools::class)
        ->and($definitions['found_by_path']->source)->toBe('test-plugin')
        ->and($this->event->getTools()['test-plugin'])->toContain(DiscoveredTools::class);
});

it('still records the path for anything that reads the list', function (): void {
    $this->event->addDiscoveryPath($this->fixturePath, ['Fixtures'], 'test-plugin');

    expect($this->event->getDiscoveryPaths()['test-plugin'])
        ->toBe(['path' => $this->fixturePath, 'subdirs' => ['Fixtures']]);
});

it('reports a path that does not exist and registers nothing', function (): void {
    $this->event->addDiscoveryPath('/non/existent/path', ['.'], 'test-plugin');

    expect($this->event->getErrors())->toHaveCount(1)
        ->and($this->event->getErrors()[0])->toContain('does not exist')
        ->and($this->event->getDefinitions())->toBe([]);
});

it('does not scan a reserved source', function (): void {
    // Core registers its classes by name through addCoreTools() and declares the
    // whole plugin tree as its path, so scanning it would tokenize every file
    // in src/ to find nothing that is not already registered.
    $this->event->addDiscoveryPath($this->fixturePath, ['Fixtures'], 'core');

    expect($this->event->getDefinitions())->toBe([])
        ->and($this->event->getDiscoveryPaths())->toHaveKey('core');
});
