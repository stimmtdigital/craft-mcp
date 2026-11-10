<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integration;

use Craft;
use craft\config\McpConfig;
use ReflectionClass;
use ReflectionProperty;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\Mcp;
use Throwable;

/**
 * Whether Craft's MCP server and this plugin are set up to work together.
 *
 * Shown on the plugin's settings screen and by `craft mcp/check`. Every check
 * that can fail says what to change, because each of these failures is silent
 * where it happens: a client sees a 403 or a missing tool, not a reason.
 */
final class Status {
    /**
     * @return list<Check>
     */
    public static function run(): array {
        $general = Craft::$app->getConfig()->getGeneral();
        $craft = Craft::$app->get('mcp')->getSettings();
        $settings = Mcp::settings();
        $checks = [];

        $checks[] = $craft->enabled
            ? new Check('Craft MCP server', Level::Ok, 'Enabled at ' . $craft->route . '.')
            : new Check('Craft MCP server', Level::Warning, 'Craft\'s own MCP server is off, so MCP clients cannot connect over HTTP. `craft mcp/serve` still works over stdio.', 'Turn it on under Settings → MCP.');

        if ($craft->enabled) {
            $checks[] = self::endpointCheck([$craft->route, $craft->authorizationRoute], $general->cpTrigger);
            $checks[] = self::keysCheck();
        }

        $checks[] = self::configCheck();
        $checks[] = $settings->enabled
            ? new Check('Plugin tools', Level::Ok, 'Added to Craft\'s MCP server' . ($settings->enableDangerousTools ? '.' : ', without the dangerous tools (code, SQL writes, GraphQL execution, backups).'))
            : new Check('Plugin tools', Level::Info, 'Off in this environment: Craft\'s MCP server runs without the stimmt_ tools.', 'Set `enabled` in config/craft-mcp.php to add them.');

        $edition = Mcp::currentEdition();
        $checks[] = $edition === Edition::Lite
            ? new Check('Edition', Level::Info, 'Lite. Pro adds validated publishing, copying entries between sites and the single Matrix-block tools.', Edition::UPGRADE_CTA)
            : new Check('Edition', Level::Ok, 'Pro.');

        return $checks;
    }

    /**
     * Craft lets a guest's control panel request whose first path segment is
     * a plugin handle go no further than the login screen, before any route
     * runs. With this plugin's handle being `mcp`, an MCP endpoint inside the
     * control panel at `mcp…` is unreachable for the clients it is meant for.
     *
     * @param list<string> $routes
     */
    public static function endpointCheck(array $routes, ?string $cpTrigger): Check {
        $blocked = array_values(array_filter($routes, static fn (string $route): bool => self::intercepted($route, $cpTrigger)));
        if ($blocked === []) {
            return new Check('Endpoint path', Level::Ok, 'Reachable next to this plugin.');
        }

        return new Check(
            'Endpoint path',
            Level::Error,
            'Craft serves ' . implode(' and ', $blocked) . ' as a control panel page of this plugin (its handle is `mcp`), so MCP clients get a login page instead of the server.',
            'Under Settings → MCP, set the endpoints to ' . self::suggestedRoute($cpTrigger) . ' and ' . self::suggestedRoute($cpTrigger) . '/auth.',
        );
    }

    public static function intercepted(string $route, ?string $cpTrigger): bool {
        $path = trim($route, '/');
        $trigger = trim((string) $cpTrigger, '/');

        if ($trigger !== '') {
            if (!str_starts_with($path . '/', $trigger . '/')) {
                return false;
            }
            $path = ltrim(substr($path, strlen($trigger)), '/');
        }

        return explode('/', $path)[0] === 'mcp';
    }

    public static function suggestedRoute(?string $cpTrigger): string {
        $trigger = trim((string) $cpTrigger, '/');

        return $trigger === '' ? '/ai' : "/{$trigger}/ai";
    }

    /**
     * The keys of a `config/mcp.php` that Craft's `McpConfig` does not have.
     * Craft throws on them as soon as its MCP server reads the file.
     *
     * @param array<array-key, mixed> $config
     * @return list<string>
     */
    public static function foreignKeys(array $config): array {
        $known = array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(McpConfig::class))->getProperties(ReflectionProperty::IS_PUBLIC),
        );
        $flat = Mcp::resolveEnvironmentConfig($config, self::environment());

        return array_values(array_diff(array_map(strval(...), array_keys($flat)), $known));
    }

    private static function configCheck(): Check {
        $path = Craft::$app->getPath()->getConfigPath() . '/mcp.php';
        $config = is_file($path) ? self::load($path) : [];
        $foreign = $config === [] ? [] : self::foreignKeys($config);

        if ($foreign === []) {
            return new Check('config/mcp.php', Level::Ok, 'Holds only Craft\'s own MCP settings.');
        }

        return new Check(
            'config/mcp.php',
            Level::Error,
            'Holds plugin settings (' . implode(', ', $foreign) . '). Since Craft 5.12 that file configures Craft\'s own MCP server, which fails on keys it does not know.',
            'Move them to config/craft-mcp.php. Keep only `instructions` and `filters` in config/mcp.php.',
        );
    }

    /**
     * Asks Craft whether the pair actually loads, rather than whether a file
     * exists: a configured key that is not valid PEM fails at the same moment
     * a missing one does, when the token is issued.
     */
    private static function keysCheck(): Check {
        if (Craft::$app->getOAuth()->hasKeys()) {
            return new Check('OAuth keys', Level::Ok, 'Craft can sign access tokens.');
        }

        return new Check(
            'OAuth keys',
            Level::Error,
            'Craft has no usable OAuth signing keys, so no MCP client can finish connecting. The plugin generates them during `craft up` when none exist; this server has none, or they do not load.',
            'Run `php craft oauth/generate-keys` on this server, or set the `privateKey` and `publicKey` OAuth config settings. Every server of an environment needs the same pair.',
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function load(string $path): array {
        try {
            $config = require $path;
        } catch (Throwable) {
            return [];
        }

        return is_array($config) ? $config : [];
    }

    private static function environment(): string {
        return Craft::$app->env ?? 'production';
    }
}
