<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\console\controllers\McpController as CraftConsoleMcpController;
use craft\controllers\McpController as CraftMcpController;
use craft\events\RegisterComponentTypesEvent;
use craft\mcp\AdminInstructions;
use craft\services\Mcp as CraftMcp;
use craft\web\View;
use Override;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\integration\Capabilities;
use stimmt\craft\Mcp\integration\Gate;
use stimmt\craft\Mcp\integration\Instructions;
use stimmt\craft\Mcp\integration\Status;
use stimmt\craft\Mcp\models\Settings;
use yii\base\Controller;
use yii\base\Event;

/**
 * Craft MCP: developer tools, Commerce and content extras for the MCP server
 * Craft has shipped since 5.12.
 *
 * The plugin is no longer a server. It registers capability classes into
 * Craft's own (`craft\services\Mcp`), which brings the transport, the OAuth
 * connection, scopes and stdio, and it stays out of Craft's way: Craft's own
 * MCP routes share this plugin's handle, so they are handed back to Craft's
 * controllers (see createController()).
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class Mcp extends BasePlugin {
    /**
     * Event fired to let plugins register elements field translators, used by
     * the Pro write tools that take field payloads.
     */
    public const EVENT_REGISTER_FIELD_TRANSLATORS = 'registerFieldTranslators';

    /**
     * The plugin's own config file. `config/mcp.php` belongs to Craft's MCP
     * server since Craft 5.12, and Craft refuses keys it does not know there.
     */
    public const string CONFIG_FILE = 'craft-mcp.php';

    public string $schemaVersion = '2.0.0';

    public bool $hasCpSettings = true;

    private static ?Settings $loadedSettings = null;

    /**
     * @return string[]
     */
    #[Override]
    public static function editions(): array {
        return Edition::ordered();
    }

    /**
     * The plugin's active edition, defaulting to Lite when the plugin is
     * not yet loaded or the stored handle is unknown.
     */
    public static function currentEdition(): Edition {
        return Edition::fromHandle(self::getInstance()?->edition);
    }

    #[Override]
    public function init(): void {
        parent::init();

        Event::on(CraftMcp::class, CraftMcp::EVENT_REGISTER_CAPABILITY_TYPES, static function (RegisterComponentTypesEvent $event): void {
            array_push($event->types, ...Capabilities::classes());
        });
        Event::on(CraftMcp::class, CraftMcp::EVENT_DISCOVERING_CAPABILITY, Gate::onDiscovering(...));
        Event::on(AdminInstructions::class, AdminInstructions::EVENT_COLLECT_SERVER_INSTRUCTIONS, Instructions::onCollect(...));
    }

    /**
     * Hands every route this module does not own back to Craft's own MCP
     * controller.
     *
     * WHY: Craft routes its MCP server as `mcp/authenticated`, `mcp/public`,
     * `mcp/server-card`, `mcp/catalog`, `mcp/upload` and, on the console,
     * `mcp/serve`. Yii resolves the `mcp` segment to a module before it looks
     * for a core controller, and a plugin is a module under its handle, so
     * without this every one of those routes reached this plugin and 404ed:
     * Craft's MCP could not run while the plugin was installed. The handle
     * stays `mcp` because the Plugin Store listing and its licenses hang off it.
     *
     * @return array{0: Controller, 1: string}|false
     */
    #[Override]
    public function createController($route) {
        $own = parent::createController($route);
        if ($own !== false) {
            return $own;
        }

        $class = Craft::$app->getRequest()->getIsConsoleRequest() ? CraftConsoleMcpController::class : CraftMcpController::class;

        return [Craft::createObject($class, ['mcp', Craft::$app]), $route];
    }

    #[Override]
    protected function createSettingsModel(): ?Model {
        return new Settings();
    }

    #[Override]
    protected function settingsHtml(): ?string {
        $view = Craft::$app->getView();
        if (!$view instanceof View) {
            return null;
        }

        return $view->renderTemplate('mcp/_settings', [
            'checks' => Status::run(),
            'configFile' => self::CONFIG_FILE,
        ]);
    }

    /**
     * The plugin settings, with config/craft-mcp.php applied over the
     * production defaults.
     */
    public static function settings(): Settings {
        if (self::$loadedSettings !== null) {
            return self::$loadedSettings;
        }

        $settings = self::getInstance()?->getSettings();

        return self::$loadedSettings = self::applyConfigOverrides($settings instanceof Settings ? $settings : new Settings());
    }

    /**
     * Forget the loaded settings, so the next call reads the config file
     * again. For tests and long-lived processes.
     */
    public static function resetSettings(): void {
        self::$loadedSettings = null;
    }

    /**
     * Production safety defaults are applied FIRST and unconditionally, so
     * creating a config file never silently re-enables dangerous tools in
     * production; only an explicit key in the file overrides them.
     */
    private static function applyConfigOverrides(Settings $settings): Settings {
        $settings = self::applyProductionDefaults($settings);

        $path = Craft::$app->getPath()->getConfigPath() . '/' . self::CONFIG_FILE;
        if (!file_exists($path)) {
            return $settings;
        }

        $config = require $path;
        if (!is_array($config)) {
            return $settings;
        }

        foreach (self::resolveEnvironmentConfig($config, self::environment()) as $key => $value) {
            if (is_string($key) && property_exists($settings, $key)) {
                $settings->$key = $value;
            }
        }

        return $settings;
    }

    /**
     * Craft's multi-environment config convention: a file with a '*' key is
     * environment-nested; the '*' base is merged with the current
     * environment's block. Files without a '*' key are flat and untouched.
     *
     * @param array<array-key, mixed> $config
     * @return array<array-key, mixed>
     */
    public static function resolveEnvironmentConfig(array $config, string $environment): array {
        if (!isset($config['*']) || !is_array($config['*'])) {
            return $config;
        }

        $environmentConfig = $config[$environment] ?? [];

        return array_merge($config['*'], is_array($environmentConfig) ? $environmentConfig : []);
    }

    private static function environment(): string {
        return Craft::$app->env ?? (getenv('CRAFT_ENVIRONMENT') ?: 'production');
    }

    private static function applyProductionDefaults(Settings $settings): Settings {
        // Only the dangerous tools: Craft's MCP server being on in production
        // is already an admin's explicit choice, and the content tools are as
        // safe there as Craft's own.
        if (self::environment() === 'production') {
            $settings->enableDangerousTools = false;
        }

        return $settings;
    }
}
