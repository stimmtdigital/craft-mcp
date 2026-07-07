<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer;

/**
 * Resolves the actual project root when Craft lives in a subdirectory.
 *
 * Walks up from Craft's @root looking for project markers (.ddev, .git)
 * to detect monorepo layouts where Craft is nested (e.g. playground/backend/).
 *
 * SRP: sole responsibility is project root detection — environment detection
 * stays in EnvironmentDetector.
 */
final readonly class ProjectRootResolver {
    private const int MAX_DEPTH = 3;

    private const array MARKERS = ['.ddev', '.git'];

    public function __construct(
        private string $craftRoot,
    ) {
    }

    /**
     * Walk up from Craft root looking for project markers (.ddev, .git).
     *
     * Returns the first parent directory containing a marker,
     * or the Craft root itself if no marker is found.
     */
    public function resolve(): string {
        $current = $this->craftRoot;

        for ($i = 0; $i < self::MAX_DEPTH; $i++) {
            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            if ($this->hasMarker($parent)) {
                return $parent;
            }

            $current = $parent;
        }

        return $this->craftRoot;
    }

    /**
     * Get the relative path from project root to Craft root.
     *
     * Returns null if Craft IS the project root (no subdirectory).
     *
     * @example "backend" for playground/backend/
     */
    public function getSubdirectory(string $projectRoot): ?string {
        if ($projectRoot === $this->craftRoot) {
            return null;
        }

        $relative = ltrim(
            str_replace($projectRoot, '', $this->craftRoot),
            DIRECTORY_SEPARATOR,
        );

        return $relative !== '' ? $relative : null;
    }

    private function hasMarker(string $directory): bool {
        return array_any(self::MARKERS, fn ($marker) => is_dir($directory . DIRECTORY_SEPARATOR . $marker));
    }
}
