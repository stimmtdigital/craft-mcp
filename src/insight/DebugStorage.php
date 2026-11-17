<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\insight;

/**
 * Reads the files the Yii debug module writes: index.data, a serialized
 * array of request summaries, and one {tag}.data per request, a serialized
 * array of panel id => serialized panel data.
 *
 * WHY allowed_classes is false on every unserialize: these are PHP
 * serialization, and unserializing an object runs its magic methods. A
 * planted or tampered file must not be able to run code through this tool.
 * The summaries and the db panel are arrays of scalars, so nothing this tool
 * reads needs an object; the exceptions a data file may also carry come back
 * as inert __PHP_Incomplete_Class values and are never touched. Craft's own
 * craft\debug\LogTarget reads these files the same way.
 */
final readonly class DebugStorage {
    public function __construct(private string $directory) {
    }

    public function directory(): string {
        return $this->directory;
    }

    /**
     * Whether the module has ever written an index here. Asked apart from
     * manifest() so "never recorded" and "recorded but unreadable" do not
     * collapse into the same answer.
     */
    public function hasIndex(): bool {
        return is_file($this->directory . '/index.data');
    }

    /**
     * The index, or null when it cannot be read or does not unserialize to
     * an array.
     *
     * @return array<mixed>|null
     */
    public function manifest(): ?array {
        return $this->read($this->directory . '/index.data');
    }

    /**
     * One panel's saved data for one request, or null when the request's file
     * is gone (the module prunes old ones) or the panel did not save.
     *
     * @return array<mixed>|null
     */
    public function panel(string $tag, string $panel): ?array {
        if (!DebugIndex::isTag($tag)) {
            return null;
        }

        $panels = $this->read("{$this->directory}/{$tag}.data");
        $serialized = $panels[$panel] ?? null;

        return is_string($serialized) ? $this->decode($serialized) : null;
    }

    /**
     * @return array<mixed>|null
     */
    private function read(string $path): ?array {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $this->decode($contents);
    }

    /**
     * The warning is swallowed on purpose: a file truncated mid-write makes
     * unserialize warn, and a half-written request file is an ordinary state
     * of this directory rather than an error. The false it returns is the
     * signal, and the callers turn it into null.
     *
     * @return array<mixed>|null
     */
    private function decode(string $serialized): ?array {
        set_error_handler(static fn (): bool => true);

        try {
            $value = unserialize($serialized, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        return is_array($value) ? $value : null;
    }
}
