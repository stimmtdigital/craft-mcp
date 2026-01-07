<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use Exception;
use JsonException;
use Override;
use stimmt\craft\Mcp\installer\clients\ClaudeCodeClient;
use stimmt\craft\Mcp\installer\clients\ClaudeDesktopClient;
use stimmt\craft\Mcp\installer\clients\CursorClient;
use stimmt\craft\Mcp\installer\ConfigWriter;
use stimmt\craft\Mcp\installer\contracts\McpClientInterface;
use stimmt\craft\Mcp\installer\EnvironmentDetector;
use stimmt\craft\Mcp\installer\WriteResult;
use yii\console\ExitCode;

/**
 * MCP client configuration installer.
 *
 * Interactive wizard to generate configuration files for various MCP clients
 * (Claude Code, Cursor, Claude Desktop).
 *
 * @author stimmt.digital
 */
class InstallController extends Controller {
    /**
     * @var string|null Override the detected environment (ddev or native)
     */
    public ?string $environment = null;

    /**
     * @var string The server name to use in configuration
     */
    public string $serverName = 'craft-cms';

    /**
     * @inheritdoc
     */
    #[Override]
    public function options($actionID): array {
        $options = parent::options($actionID);

        if ($actionID === 'index') {
            $options[] = 'environment';
            $options[] = 'serverName';
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    #[Override]
    public function optionAliases(): array {
        return [
            'e' => 'environment',
            's' => 'serverName',
        ];
    }

    /**
     * Generate MCP client configuration files.
     *
     * This wizard helps you create configuration files for various MCP clients
     * so they can connect to the Craft MCP server.
     *
     * @return int Exit code
     */
    public function actionIndex(): int {
        $projectRoot = Craft::getAlias('@root');
        $envDetector = new EnvironmentDetector($projectRoot);
        $configWriter = new ConfigWriter();

        $this->printHeader();

        // 1. Resolve environment
        $environment = $this->resolveEnvironment($envDetector);

        // 2. Select clients to configure
        $clients = $this->selectClients($projectRoot, $envDetector);

        if ($clients === []) {
            $this->stdout(PHP_EOL . 'No clients selected. Nothing to do.' . PHP_EOL, Console::FG_YELLOW);

            return ExitCode::OK;
        }

        // 3. Get project path if any client needs absolute paths
        $projectPath = $this->resolveProjectPath($clients, $projectRoot);

        // 4. Get server name
        $serverName = $this->resolveServerName($configWriter, $clients);

        // 5. Generate and write configurations
        $this->stdout(PHP_EOL . 'Generating configuration files...' . PHP_EOL . PHP_EOL);

        foreach ($clients as $client) {
            $this->writeClientConfig($client, $environment, $projectPath, $serverName, $configWriter);
        }

        $this->printSuccessMessage();

        return ExitCode::OK;
    }

    /**
     * Print the wizard header.
     */
    private function printHeader(): void {
        $this->stdout(PHP_EOL);
        $this->stdout('MCP Client Configuration Wizard' . PHP_EOL, Console::FG_CYAN, Console::BOLD);
        $this->stdout(str_repeat('=', 32) . PHP_EOL . PHP_EOL);
    }

    /**
     * Resolve the environment to use (DDEV or native).
     */
    private function resolveEnvironment(EnvironmentDetector $envDetector): string {
        // Use command-line option if provided
        if ($this->environment !== null) {
            return $this->environment;
        }

        $detected = $envDetector->detect();
        $envName = $detected === EnvironmentDetector::DDEV ? 'DDEV' : 'Native PHP';

        $this->stdout("Detected environment: {$envName}" . PHP_EOL);

        if (!$this->interactive) {
            return $detected;
        }

        if ($this->confirm('Use this environment?', true)) {
            return $detected;
        }

        return $this->select('Select environment:', [
            EnvironmentDetector::DDEV => 'DDEV (containerized)',
            EnvironmentDetector::NATIVE => 'Native PHP',
        ]);
    }

    /**
     * Let user select which clients to configure.
     *
     * @return McpClientInterface[]
     */
    private function selectClients(string $projectRoot, EnvironmentDetector $envDetector): array {
        $available = $this->getAvailableClients($projectRoot, $envDetector);

        $this->stdout(PHP_EOL . 'Which MCP clients would you like to configure?' . PHP_EOL);
        $this->stdout('(You can configure multiple clients)' . PHP_EOL . PHP_EOL);

        $selected = [];

        foreach ($available as $client) {
            $label = sprintf('%s (%s)', $client->getName(), $client->getDescription());
            $default = $client->getId() !== 'claude-desktop'; // Default yes for all except Claude Desktop

            if ($this->confirm("  Configure {$label}?", $default)) {
                $selected[] = $client;
            }
        }

        return $selected;
    }

    /**
     * Get all available MCP clients.
     *
     * @return McpClientInterface[]
     */
    private function getAvailableClients(string $projectRoot, EnvironmentDetector $envDetector): array {
        return [
            new ClaudeCodeClient($projectRoot, $envDetector),
            new CursorClient($projectRoot, $envDetector),
            new ClaudeDesktopClient($projectRoot, $envDetector),
        ];
    }

    /**
     * Check if any client requires absolute paths.
     *
     * @param McpClientInterface[] $clients
     */
    private function anyClientRequiresAbsolutePaths(array $clients): bool {
        foreach ($clients as $client) {
            if ($client->requiresAbsolutePaths()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the project path if any client needs absolute paths.
     *
     * @param McpClientInterface[] $clients
     */
    private function resolveProjectPath(array $clients, string $defaultPath): ?string {
        $needsAbsolutePaths = $this->anyClientRequiresAbsolutePaths($clients);

        if (!$needsAbsolutePaths) {
            return null;
        }

        $this->stdout(PHP_EOL);

        return $this->prompt('Project path for Claude Desktop', [
            'default' => $defaultPath,
            'required' => true,
        ]);
    }

    /**
     * Resolve the server name, checking for conflicts.
     *
     * @param McpClientInterface[] $clients
     */
    private function resolveServerName(ConfigWriter $configWriter, array $clients): string {
        $this->stdout(PHP_EOL);

        $serverName = $this->prompt('Server name', [
            'default' => $this->serverName,
            'required' => true,
        ]);

        // Check for existing servers with this name
        $conflicts = [];

        foreach ($clients as $client) {
            if ($configWriter->serverExists($client->getConfigPath(), $serverName)) {
                $conflicts[] = $client->getName();
            }
        }

        if ($conflicts !== []) {
            $this->stdout(PHP_EOL);
            $this->stdout(
                sprintf(
                    "Warning: Server '%s' already exists in: %s" . PHP_EOL,
                    $serverName,
                    implode(', ', $conflicts),
                ),
                Console::FG_YELLOW,
            );

            if (!$this->confirm('Overwrite existing configuration?', false)) {
                // Let user choose a different name
                return $this->prompt('Enter a different server name', [
                    'required' => true,
                ]);
            }
        }

        return $serverName;
    }

    /**
     * Write configuration for a single client.
     */
    private function writeClientConfig(
        McpClientInterface $client,
        string $environment,
        ?string $projectPath,
        string $serverName,
        ConfigWriter $configWriter,
    ): void {
        $config = $client->generateServerConfig($environment, $projectPath);

        try {
            $result = $configWriter->write($client->getConfigPath(), $serverName, $config);
            $this->printWriteResult($result);
        } catch (JsonException $e) {
            $this->stderr(
                sprintf(" ✗ %s: Failed to parse existing config - %s\n", $client->getName(), $e->getMessage()),
                Console::FG_RED,
            );
        } catch (Exception $e) {
            $this->stderr(
                sprintf(" ✗ %s: %s\n", $client->getName(), $e->getMessage()),
                Console::FG_RED,
            );
        }
    }

    /**
     * Print the result of a write operation.
     */
    private function printWriteResult(WriteResult $result): void {
        $relativePath = $this->getRelativePath($result->path);

        $this->stdout(' ✓ ', Console::FG_GREEN);
        $this->stdout(sprintf('%s: %s', $result->getDescription(), $relativePath) . PHP_EOL);
    }

    /**
     * Get a display-friendly relative path.
     */
    private function getRelativePath(string $path): string {
        $home = getenv('HOME');

        if ($home !== false && str_starts_with($path, $home)) {
            return '~' . substr($path, strlen($home));
        }

        $projectRoot = Craft::getAlias('@root');

        if (str_starts_with($path, $projectRoot)) {
            return '.' . substr($path, strlen($projectRoot));
        }

        return $path;
    }

    /**
     * Print the success message with next steps.
     */
    private function printSuccessMessage(): void {
        $this->stdout(PHP_EOL);
        $this->stdout('Success! MCP client configuration files have been generated.' . PHP_EOL, Console::FG_GREEN);
        $this->stdout(PHP_EOL);
        $this->stdout('Next steps:' . PHP_EOL, Console::BOLD);
        $this->stdout('  Restart your MCP clients to pick up the new configuration.' . PHP_EOL);
        $this->stdout(PHP_EOL);
        $this->stdout('Documentation: ', Console::FG_GREY);
        $this->stdout('https://github.com/stimmtdigital/craft-mcp' . PHP_EOL);
        $this->stdout(PHP_EOL);
    }
}
