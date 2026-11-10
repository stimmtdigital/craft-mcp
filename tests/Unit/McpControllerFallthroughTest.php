<?php

declare(strict_types=1);

require_once __DIR__ . '/../Fixtures/RealCraft.php';

use craft\console\controllers\McpController as CraftConsoleMcpController;
use craft\controllers\McpController as CraftMcpController;
use stimmt\craft\Mcp\console\controllers\CheckController;
use stimmt\craft\Mcp\Mcp;
use yii\base\Request;
use yii\base\Response;

/**
 * The plugin's handle is `mcp`, and Yii resolves a route's first segment to a
 * module before it looks for a core controller, so every one of Craft's own
 * MCP routes (`mcp/authenticated`, `mcp/serve`, ...) reaches this plugin
 * first. Mcp::createController() hands every route it does not own back to
 * Craft's controller; without it Craft's MCP server 404s while the plugin is
 * installed.
 *
 * The plugin is built without its constructor: Plugin::__construct() and
 * init() register aliases, translations and event handlers against a booted
 * application, none of which routing touches. The application is a stand-in
 * exposing the request and response components a controller resolves in
 * yii\base\Controller::init(), with the console flag the plugin branches on.
 * The controllers themselves are Craft's real classes.
 */
$plugin = static function (bool $console): Mcp {
    $request = new class () extends Request {
        public function resolve(): array {
            return [];
        }
    };
    $request->setIsConsoleRequest($console);

    Craft::$app = new class ($request) {
        public function __construct(private readonly Request $request) {
        }

        public function getRequest(): Request {
            return $this->request;
        }

        public function has(string $id): bool {
            return in_array($id, ['request', 'response'], true);
        }

        public function get(string $id): object {
            return $id === 'request' ? $this->request : new Response();
        }
    };

    $plugin = (new ReflectionClass(Mcp::class))->newInstanceWithoutConstructor();
    // What Plugin::__construct() derives from the class's namespace.
    $plugin->controllerNamespace = $console ? 'stimmt\\craft\\Mcp\\console\\controllers' : 'stimmt\\craft\\Mcp\\controllers';

    return $plugin;
};

describe('Mcp::createController()', function () use ($plugin) {
    beforeEach(function () {
        $this->previousApp = Craft::$app;
    });

    afterEach(function () {
        Craft::$app = $this->previousApp;
    });

    it('hands a web route it does not own to Craft\'s MCP controller', function (string $route) use ($plugin) {
        [$controller, $action] = $plugin(false)->createController($route);

        expect($controller)->toBeInstanceOf(CraftMcpController::class)
            ->and($controller->id)->toBe('mcp')
            ->and($controller->module)->toBe(Craft::$app)
            ->and($action)->toBe($route);
    })->with(['authenticated', 'public', 'server-card', 'catalog', 'upload']);

    it('hands the console serve route to Craft\'s console MCP controller', function () use ($plugin) {
        [$controller, $action] = $plugin(true)->createController('serve');

        expect($controller)->toBeInstanceOf(CraftConsoleMcpController::class)
            ->and($controller->id)->toBe('mcp')
            ->and($controller->module)->toBe(Craft::$app)
            ->and($action)->toBe('serve');
    });

    // `craft mcp/check` is the plugin's own command and must stay so.
    it('keeps the routes the plugin does own', function () use ($plugin) {
        $mcp = $plugin(true);
        [$controller] = $mcp->createController('check');

        expect($controller)->toBeInstanceOf(CheckController::class)
            ->and($controller->module)->toBe($mcp);
    });
});
