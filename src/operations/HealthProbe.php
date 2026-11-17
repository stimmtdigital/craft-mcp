<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use Craft;
use craft\db\Connection;
use craft\models\Updates as UpdatesModel;
use craft\queue\Queue;
use stimmt\craft\Mcp\integration\Check;
use stimmt\craft\Mcp\integration\Level;
use stimmt\craft\Mcp\integration\Status;
use Throwable;

/**
 * Gathers the install's health from Craft, area by area, and hands each
 * reading to the rule that judges it.
 *
 * Nothing here writes: every Craft call was chosen for being a read (see the
 * notes on projectConfig() and updates()), and the queue is read through
 * QueueTable for the same reason.
 */
final class HealthProbe {
    public const int ERROR_WINDOW_HOURS = 24;

    /**
     * @return list<HealthCheck>
     */
    public static function run(): array {
        $areas = [
            'mcp' => self::mcp(...),
            'environment' => self::environment(...),
            'updates' => self::updates(...),
            'projectConfig' => self::projectConfig(...),
            'queue' => self::queue(...),
            'storage' => self::storage(...),
            'errors' => self::errors(...),
        ];

        $checks = [];
        foreach ($areas as $area => $probe) {
            $checks = [...$checks, ...self::guarded($area, $probe)];
        }

        return $checks;
    }

    /**
     * One area failing to read must not take the rest of the report with it.
     *
     * WHY the exception's class and not its message: a message from a failed
     * connection or config read can quote a DSN or a credential, and this
     * output goes to a model's context.
     *
     * @param callable(): list<Check> $probe
     * @return list<HealthCheck>
     */
    private static function guarded(string $area, callable $probe): array {
        try {
            $checks = $probe();
        } catch (Throwable $e) {
            $checks = [new Check(
                ucfirst($area),
                Level::Info,
                'Could not determine (' . $e::class . ' while reading it).',
            )];
        }

        return array_map(static fn (Check $check): HealthCheck => new HealthCheck($area, $check), $checks);
    }

    /**
     * @return list<Check>
     */
    private static function mcp(): array {
        return Status::run();
    }

    /**
     * @return list<Check>
     */
    private static function environment(): array {
        $general = Craft::$app->getConfig()->getGeneral();
        $db = Craft::$app->getDb();
        $environment = Craft::$app->env ?? null;
        // Craft's own connection names MariaDB apart from MySQL; a plain Yii
        // one only knows its PDO driver.
        $driver = $db instanceof Connection ? $db->getDriverLabel() : $db->getDriverName();

        return [
            EnvironmentRules::devMode($general->devMode, $environment),
            EnvironmentRules::allowAdminChanges($general->allowAdminChanges, $environment),
            EnvironmentRules::php(self::craftPhpConstraint(), PHP_VERSION),
            EnvironmentRules::database((string) $driver, $db->getServerVersion()),
        ];
    }

    /**
     * Read from Craft's own composer.json, so the requirement moves with the
     * installed Craft rather than with this plugin.
     */
    private static function craftPhpConstraint(): ?string {
        $manifest = Craft::getAlias('@craftcms/composer.json', false);
        $json = is_string($manifest) && is_file($manifest) ? file_get_contents($manifest) : false;
        $require = $json === false ? null : (json_decode($json, true)['require']['php'] ?? null);

        return is_string($require) ? $require : null;
    }

    /**
     * @return list<Check>
     */
    private static function updates(): array {
        $updates = Craft::$app->getUpdates();

        return [
            UpdateRules::migrations($updates->getPendingMigrationHandles(true)),
            UpdateRules::schema($updates->getIsCraftSchemaVersionCompatible()),
            UpdateRules::available(self::cachedUpdates($updates->cacheKey)),
        ];
    }

    /**
     * Straight from the cache rather than through Updates::getUpdates(), which
     * contacts the update server when the cache entry is gone, including when
     * it expires between an "is it cached" check and the read.
     *
     * @return array{total: int, critical: bool, names: list<string>}|null
     */
    private static function cachedUpdates(string $cacheKey): ?array {
        $cached = Craft::$app->getCache()->get($cacheKey);
        if (!is_array($cached)) {
            return null;
        }

        $model = new UpdatesModel($cached);
        $names = $model->cms->getHasReleases() ? ['craft'] : [];
        foreach ($model->plugins as $handle => $update) {
            if ($update->getHasReleases()) {
                $names[] = (string) $handle;
            }
        }

        return ['total' => $model->getTotal(), 'critical' => $model->getHasCritical(), 'names' => $names];
    }

    /**
     * WHY not ProjectConfig::areChangesPending(): when the YAML is missing it
     * regenerates it, which writes config/project. getPendingChangeSummary()
     * is what Craft's own read-only project_config_status tool uses.
     *
     * @return list<Check>
     */
    private static function projectConfig(): array {
        $projectConfig = Craft::$app->getProjectConfig();
        $issues = [];
        $projectConfig->getAreConfigSchemaVersionsCompatible($issues);

        $pending = null;
        if ($projectConfig->getDoesExternalConfigExist()) {
            $pending = array_sum(array_map(count(...), $projectConfig->getPendingChangeSummary()));
        }

        return [ProjectConfigRules::schema($issues), ProjectConfigRules::pending($pending)];
    }

    /**
     * @return list<Check>
     */
    private static function queue(): array {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof Queue) {
            return [QueueRules::uninspectable($queue::class)];
        }

        $table = new QueueTable($queue);

        return [
            QueueRules::failed($table->failed()),
            QueueRules::waiting($table->waiting(), $table->oldestWaitingAge()),
        ];
    }

    /**
     * @return list<Check>
     */
    private static function storage(): array {
        $storage = Craft::$app->getPath()->getStoragePath(false);
        $runtime = Craft::$app->getRuntimePath();

        return [
            StorageRules::writable('Storage directory', $storage, is_dir($storage) && is_writable($storage)),
            StorageRules::writable('Runtime directory', $runtime, is_dir($runtime) && is_writable($runtime)),
            StorageRules::freeSpace(is_dir($storage) ? disk_free_space($storage) : false),
        ];
    }

    /**
     * @return list<Check>
     */
    private static function errors(): array {
        $scan = ErrorScan::run(
            Craft::$app->getPath()->getLogPath(false),
            Craft::$app->getTimeZone(),
            self::ERROR_WINDOW_HOURS,
            null,
        );

        return [LogRules::recentErrors(count($scan->groups->inWindow()), self::ERROR_WINDOW_HOURS, $scan->complete())];
    }
}
