<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use ReflectionException;
use ReflectionProperty;
use yii\mutex\Mutex;

/**
 * Releases mutex locks accumulated during long-running MCP process execution.
 *
 * In a standard Craft request, mutexes are released automatically via Yii2's
 * shutdown function (Mutex::init registers it) or object destruction. In the
 * MCP server — a long-running process — neither runs between tool calls.
 *
 * This creates two problems:
 * 1. Yii2 Mutex::$_locks (private) retains lock names, so the underlying
 *    file/db lock is never released — blocking other processes (e.g. the CP).
 * 2. Craft ProjectConfig::$_locked (private) stays true, causing the service
 *    to skip re-acquiring the mutex on subsequent operations — a silent race
 *    condition if another process acquires the lock in between.
 *
 * @see https://github.com/stimmtdigital/craft-mcp/issues/7
 * @author Max van Essen <support@stimmt.digital>
 */
final class MutexGuard {
    /**
     * Release all held mutex locks and reset Craft's internal lock state.
     *
     * Safe to call unconditionally — releasing an unheld lock is a no-op.
     */
    public static function releaseAll(): void {
        self::releaseYiiMutexLocks();
        self::resetProjectConfigLockState();
    }

    /**
     * Release all locks tracked by Yii2's Mutex via Reflection.
     *
     * Yii2's Mutex::$_locks is private with no public accessor. Reflection is
     * the only way to discover which locks are held. Falls back to releasing
     * the known problematic 'project-config' lock if Reflection fails.
     *
     * Note: Reflection targets the declaring class (Mutex) directly, since
     * private properties are not visible when reflecting on a subclass.
     */
    private static function releaseYiiMutexLocks(): void {
        $mutex = Craft::$app->getMutex();

        try {
            $property = new ReflectionProperty(Mutex::class, '_locks');
            $locks = $property->getValue($mutex);

            foreach ($locks as $lock) {
                $mutex->release($lock);
            }
        } catch (ReflectionException) {
            $mutex->release('project-config');
        }
    }

    /**
     * Reset ProjectConfig's internal _locked flag.
     *
     * Without this, ProjectConfig::_acquireLock() sees _locked=true and skips
     * the mutex acquire — even though the mutex was just released above.
     */
    private static function resetProjectConfigLockState(): void {
        try {
            $projectConfig = Craft::$app->getProjectConfig();
            $property = new ReflectionProperty($projectConfig, '_locked');
            $property->setValue($projectConfig, false);
        } catch (ReflectionException) {
            // Non-critical in single-process context
        }
    }
}
