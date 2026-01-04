<?php

declare(strict_types=1);

use stimmt\craft\Mcp\events\RegisterToolsEvent;
use stimmt\craft\Mcp\Tests\Fixtures\AbstractToolClass;
use stimmt\craft\Mcp\Tests\Fixtures\InvalidToolClass;
use stimmt\craft\Mcp\Tests\Fixtures\ValidToolClass;

beforeEach(function () {
    $this->event = new RegisterToolsEvent();
});

describe('RegisterToolsEvent::addTool()', function () {
    it('registers valid tool class', function () {
        $this->event->addTool(ValidToolClass::class, 'test-plugin');

        $tools = $this->event->getTools();

        expect($tools)
            ->toHaveKey('test-plugin')
            ->and($tools['test-plugin'])->toContain(ValidToolClass::class);
    });

    it('uses default source when not provided', function () {
        $this->event->addTool(ValidToolClass::class);

        $tools = $this->event->getTools();

        expect($tools)->toHaveKey('plugin');
    });

    it('groups tools by source', function () {
        $this->event->addTool(ValidToolClass::class, 'plugin-a');
        $this->event->addTool(ValidToolClass::class, 'plugin-b');

        $tools = $this->event->getTools();

        expect($tools)
            ->toHaveKey('plugin-a')
            ->toHaveKey('plugin-b');
    });

    it('allows multiple tools per source', function () {
        $this->event->addTool(ValidToolClass::class, 'my-plugin');
        $this->event->addTool(ValidToolClass::class, 'my-plugin');

        $tools = $this->event->getTools();

        expect($tools['my-plugin'])->toHaveCount(2);
    });

    it('rejects non-existent class', function () {
        $this->event->addTool('NonExistent\\FakeClass', 'test');

        $errors = $this->event->getErrors();
        $tools = $this->event->getTools();

        expect($errors)->not->toBeEmpty()
            ->and($errors[0])->toContain('does not exist')
            ->and($tools)->not->toHaveKey('test');
    });

    it('rejects abstract class', function () {
        $this->event->addTool(AbstractToolClass::class, 'test');

        $errors = $this->event->getErrors();

        expect($errors)->not->toBeEmpty()
            ->and($errors[0])->toContain('abstract');
    });

    it('rejects class without McpTool attribute', function () {
        $this->event->addTool(InvalidToolClass::class, 'test');

        $errors = $this->event->getErrors();

        expect($errors)->not->toBeEmpty()
            ->and($errors[0])->toContain('McpTool');
    });
});

describe('RegisterToolsEvent::getAllToolClasses()', function () {
    it('returns empty array when no tools registered', function () {
        expect($this->event->getAllToolClasses())->toBe([]);
    });

    it('returns flat list of all tool classes', function () {
        $this->event->addTool(ValidToolClass::class, 'plugin-a');
        $this->event->addTool(ValidToolClass::class, 'plugin-b');

        $allClasses = $this->event->getAllToolClasses();

        expect($allClasses)
            ->toHaveCount(2)
            ->each->toBe(ValidToolClass::class);
    });
});

describe('RegisterToolsEvent::addDiscoveryPath()', function () {
    it('registers valid directory path', function () {
        $path = dirname(__DIR__, 3) . '/src/tools';
        $this->event->addDiscoveryPath($path, ['tools'], 'test-plugin');

        $paths = $this->event->getDiscoveryPaths();

        expect($paths)
            ->toHaveKey('test-plugin')
            ->and($paths['test-plugin']['path'])->toBe($path)
            ->and($paths['test-plugin']['subdirs'])->toBe(['tools']);
    });

    it('rejects non-existent directory', function () {
        $this->event->addDiscoveryPath('/non/existent/path', ['.'], 'test');

        $errors = $this->event->getErrors();

        expect($errors)->not->toBeEmpty()
            ->and($errors[0])->toContain('does not exist');
    });

    it('stores multiple subdirectories', function () {
        $path = dirname(__DIR__, 3) . '/src';
        $this->event->addDiscoveryPath($path, ['.', 'tools', 'support'], 'test');

        $paths = $this->event->getDiscoveryPaths();

        expect($paths['test']['subdirs'])->toBe(['.', 'tools', 'support']);
    });
});

describe('RegisterToolsEvent::getErrors()', function () {
    it('returns empty array when no errors', function () {
        $this->event->addTool(ValidToolClass::class, 'test');

        expect($this->event->getErrors())->toBe([]);
    });

    it('collects multiple errors', function () {
        $this->event->addTool('Fake\\Class1', 'plugin-a');
        $this->event->addTool('Fake\\Class2', 'plugin-b');

        $errors = $this->event->getErrors();

        expect($errors)->toHaveCount(2);
    });

    it('includes source in error message', function () {
        $this->event->addTool('Fake\\Class', 'my-plugin');

        $errors = $this->event->getErrors();

        expect($errors[0])->toContain('[my-plugin]');
    });
});

describe('RegisterToolsEvent as yii Event', function () {
    it('extends yii base Event', function () {
        expect($this->event)->toBeInstanceOf(yii\base\Event::class);
    });
});
