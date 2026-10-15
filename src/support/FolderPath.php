<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Exception\ToolCallException;

/**
 * A folder path within a volume, in Craft's own form ("blog/2026/", root is
 * ""), refused when a segment would step outside the folder it names.
 *
 * WHY: the asset tools create folders that are missing, and on a local
 * volume Craft creates the directory too. A ".." segment there is a directory
 * outside the volume; an empty segment or a backslash is a path Craft would
 * read differently from the caller.
 *
 * @author stimmt.digital
 */
final class FolderPath {
    public static function normalize(?string $path): string {
        $trimmed = trim((string) $path, '/');
        if ($trimmed === '') {
            return '';
        }

        foreach (explode('/', $trimmed) as $segment) {
            if (in_array($segment, ['', '.', '..'], true) || str_contains($segment, '\\')) {
                throw new ToolCallException(
                    "Invalid folder path '{$path}': use folder names separated by '/', such as 'blog/2026', without '.' or '..'.",
                );
            }
        }

        return $trimmed . '/';
    }
}
