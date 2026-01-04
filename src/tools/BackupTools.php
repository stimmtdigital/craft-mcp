<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\db\Connection;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use Throwable;

/**
 * Database backup tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class BackupTools {
    /**
     * List available database backups.
     */
    #[McpTool(
        name: 'list_backups',
        description: 'List available database backups from storage/backups directory',
    )]
    #[McpToolMeta(category: ToolCategory::BACKUP)]
    public function listBackups(): array {
        $backupPath = Craft::$app->getPath()->getDbBackupPath();

        if (!is_dir($backupPath)) {
            return [
                'count' => 0,
                'backups' => [],
                'path' => $backupPath,
            ];
        }

        $files = glob($backupPath . '/*.sql') ?: [];
        $backups = [];

        foreach ($files as $file) {
            $filename = basename($file);
            $size = filesize($file);
            $modified = filemtime($file);

            $backups[] = [
                'filename' => $filename,
                'path' => $file,
                'size' => $this->formatBytes($size),
                'sizeBytes' => $size,
                'created' => date('Y-m-d H:i:s', $modified),
                'timestamp' => $modified,
            ];
        }

        // Sort by timestamp descending (newest first)
        usort($backups, fn (array $a, array $b) => $b['timestamp'] <=> $a['timestamp']);

        // Remove internal timestamp field
        foreach ($backups as &$backup) {
            unset($backup['timestamp']);
        }

        return [
            'count' => count($backups),
            'backups' => $backups,
            'path' => $backupPath,
        ];
    }

    /**
     * Create a new database backup.
     */
    #[McpTool(
        name: 'create_backup',
        description: 'Create a new database backup. WARNING: This is a dangerous operation that creates files on the server.',
    )]
    #[McpToolMeta(category: ToolCategory::BACKUP, dangerous: true)]
    public function createBackup(): array {
        try {
            /** @var Connection $db */
            $db = Craft::$app->getDb();
            $backupPath = $db->backup();

            $filename = basename($backupPath);
            $size = file_exists($backupPath) ? filesize($backupPath) : 0;

            return [
                'success' => true,
                'backup' => [
                    'filename' => $filename,
                    'path' => $backupPath,
                    'size' => $this->formatBytes($size),
                    'sizeBytes' => $size,
                    'created' => date('Y-m-d H:i:s'),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Format bytes to human-readable size.
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }
}
