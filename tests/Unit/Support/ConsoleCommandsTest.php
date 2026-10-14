<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\support\ConsoleCommands;
use yii\base\Module;

/**
 * A module whose controller namespace has no path alias in the current
 * request (the headless template's requestid module defines its alias only
 * for console requests) made Yii's command walk throw, and the whole
 * list_console_commands call failed with it. One unreadable module now costs
 * only its own commands, and the answer says which module that was.
 */
describe('ConsoleCommands', function () {
    // Built without its constructor: a console controller's constructor wants
    // a running application, and the walk under test does not.
    beforeEach(function () {
        // A Yii module registers itself on the application; nothing else of
        // the application is used by the walk.
        $this->originalApp = Yii::$app;
        Yii::$app = new class () {
            public array $loadedModules = [];
        };
        $this->root = new Module('root');
        $this->root->setModules(['broken' => ['class' => Module::class, 'controllerNamespace' => 'unaliased\requestid\controllers']]);
        $this->walk = function (ConsoleCommands $commands): array {
            return (new ReflectionMethod($commands, 'getModuleCommands'))->invoke($commands, $this->root);
        };
    });

    afterEach(function () {
        Yii::$app = $this->originalApp;
    });

    it('skips a module whose controller path cannot be resolved instead of failing', function () {
        $commands = (new ReflectionClass(ConsoleCommands::class))->newInstanceWithoutConstructor();

        expect(($this->walk)($commands))->toBe([])
            ->and($commands->skipped())->toBe(['root/broken']);
    });

    it('reports nothing skipped when every module resolves', function () {
        $this->root = new Module('clean');
        $commands = (new ReflectionClass(ConsoleCommands::class))->newInstanceWithoutConstructor();
        ($this->walk)($commands);

        expect($commands->skipped())->toBe([]);
    });
});
