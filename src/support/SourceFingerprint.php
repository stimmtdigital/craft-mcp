<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A short digest of a PHP source tree, used to key caches on the state of the
 * code they were built from.
 *
 * Files are fingerprinted by path, mtime and size rather than by content. A
 * stat per file is roughly an order of magnitude cheaper than reading it, and
 * every way source actually changes moves at least one of the three: an edit,
 * a git checkout, a composer update, adding or deleting a class. The gap is a
 * rewrite that lands on the same byte count within the same second while
 * preserving the mtime, which no editor or VCS does.
 *
 * Paths are recorded relative to the scanned directory, so the same code
 * fingerprints identically whether it is read from the host or from inside a
 * container.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class SourceFingerprint {
    /**
     * Not a security hash: xxh128 is picked because it is fast and non-crypto,
     * which is exactly right for a cache key over a few hundred stat lines.
     */
    private const string ALGORITHM = 'xxh128';

    private const string EXTENSION = 'php';

    public function of(string $directory): string {
        return hash(self::ALGORITHM, implode("\n", $this->entries($directory)));
    }

    /**
     * One sorted `path:mtime:size` line per PHP file. Sorted because directory
     * iteration order is filesystem-dependent, and an unstable order would
     * change the digest without the code changing.
     *
     * @return string[]
     */
    private function entries(string $directory): array {
        if (!is_dir($directory)) {
            return [];
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $entries = [];
        foreach ($files as $file) {
            $entry = $this->entry($file, $directory);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        sort($entries);

        return $entries;
    }

    private function entry(SplFileInfo $file, string $directory): ?string {
        if ($file->isDir() || $file->getExtension() !== self::EXTENSION) {
            return null;
        }

        $path = substr($file->getPathname(), strlen($directory));

        return "{$path}:{$file->getMTime()}:{$file->getSize()}";
    }
}
