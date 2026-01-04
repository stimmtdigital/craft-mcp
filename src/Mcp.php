<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use Override;
use stimmt\craft\Mcp\events\RegisterToolsEvent;
use stimmt\craft\Mcp\models\Settings;
use stimmt\craft\Mcp\services\ToolRegistry;

/**
 * Craft MCP Plugin
 *
 * Provides MCP (Model Context Protocol) server functionality for Craft CMS.
 * This allows AI assistants like Claude to interact with Craft installations.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class Mcp extends BasePlugin {
    /**
     * Event fired to allow plugins to register MCP tools.
     */
    public const EVENT_REGISTER_TOOLS = 'registerTools';

    /**
     * Tools that can modify data or execute code.
     *
     * @deprecated Use getToolRegistry()->getDangerousTools() instead.
     *             This constant is kept for backwards compatibility.
     */
    public const DANGEROUS_TOOLS = [
        'tinker',
        'run_query',
        'create_entry',
        'update_entry',
        'clear_caches',
        'create_backup',
        'execute_graphql',
    ];

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = false;

    private static ?Settings $loadedSettings = null;

    private static ?ToolRegistry $toolRegistry = null;

    #[Override]
    public static function config(): array {
        return [
            'components' => [],
        ];
    }

    #[Override]
    public function init(): void {
        parent::init();

        Craft::info('Craft MCP plugin loaded', __METHOD__);
    }

    protected function createSettingsModel(): ?Model {
        return new Settings();
    }

    /**
     * Get the plugin settings with config file overrides applied.
     */
    public static function settings(): Settings {
        if (self::$loadedSettings !== null) {
            return self::$loadedSettings;
        }

        $plugin = self::getInstance();
        if ($plugin === null) {
            return new Settings();
        }

        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        // Apply config file overrides
        self::$loadedSettings = self::applyConfigOverrides($settings);

        return self::$loadedSettings;
    }

    /**
     * Get the tool registry instance.
     */
    public static function getToolRegistry(): ToolRegistry {
        if (self::$toolRegistry === null) {
            self::$toolRegistry = new ToolRegistry();
        }

        return self::$toolRegistry;
    }

    /**
     * Reset the tool registry to force re-initialization.
     *
     * Useful for detecting newly installed plugins without full server restart.
     */
    public static function resetToolRegistry(): void {
        self::$toolRegistry = null;
    }

    /**
     * Apply config/mcp.php overrides to settings.
     */
    private static function applyConfigOverrides(Settings $settings): Settings {
        $configPath = Craft::$app->getPath()->getConfigPath() . '/mcp.php';

        if (!file_exists($configPath)) {
            return self::applyProductionDefaults($settings);
        }

        $config = require $configPath;
        if (!is_array($config)) {
            return $settings;
        }

        foreach ($config as $key => $value) {
            if (property_exists($settings, $key)) {
                $settings->$key = $value;
            }
        }

        return $settings;
    }

    /**
     * Apply safe defaults when no config file exists.
     */
    private static function applyProductionDefaults(Settings $settings): Settings {
        $environment = Craft::$app->env ?? getenv('CRAFT_ENVIRONMENT') ?: 'production';

        if ($environment === 'production') {
            $settings->enabled = false;
            $settings->enableDangerousTools = false;
        }

        return $settings;
    }

    /**
     * Check if MCP is enabled.
     */
    public static function isEnabled(): bool {
        return self::settings()->enabled;
    }

    /**
     * Check if a specific tool is enabled.
     */
    public static function isToolEnabled(string $toolName): bool {
        $settings = self::settings();

        if (!$settings->enabled) {
            return false;
        }

        if (in_array($toolName, $settings->disabledTools, true)) {
            return false;
        }

        // Get tool definition from registry
        $definition = self::getToolRegistry()->getDefinition($toolName);

        // Check method-level condition
        if ($definition !== null && !$definition->isConditionMet()) {
            return false;
        }

        // Check if tool is dangerous
        $isDangerous = $definition !== null
            ? $definition->dangerous
            : in_array($toolName, self::DANGEROUS_TOOLS, true); // Fallback for backwards compat

        return !($isDangerous && !$settings->enableDangerousTools);
    }

    /**
     * Get all dangerous tool names.
     *
     * @return string[]
     */
    public static function getDangerousTools(): array {
        return self::getToolRegistry()->getDangerousTools();
    }

    /**
     * Collect tool classes from other plugins via event.
     *
     * @deprecated Use getToolRegistry() instead. The registry now handles all tool collection.
     * @return RegisterToolsEvent The event containing registered tools and any errors
     */
    public static function collectExternalTools(): RegisterToolsEvent {
        $event = new RegisterToolsEvent();

        $plugin = self::getInstance();
        if ($plugin !== null && $plugin->hasEventHandlers(self::EVENT_REGISTER_TOOLS)) {
            $plugin->trigger(self::EVENT_REGISTER_TOOLS, $event);
        }

        // Log any validation errors
        foreach ($event->getErrors() as $error) {
            Craft::warning("MCP tool registration error: {$error}", __METHOD__);
        }

        return $event;
    }
}
