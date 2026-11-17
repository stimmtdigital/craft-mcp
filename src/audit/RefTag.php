<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

/**
 * One Craft reference tag as written in content: `{entry:123@2:url||fallback}`.
 *
 * The parts stay strings because that is what Craft itself resolves: the ref
 * handle may be a short handle ("entry") or a class name, the ref an id or a
 * slug path, and the site an id, a handle or a UUID. Turning them into
 * classes and site ids needs the running install, so that happens in the
 * Craft-side readers, never here.
 */
final readonly class RefTag {
    public function __construct(
        public string $handle,
        public string $ref,
        public ?string $site = null,
        public ?string $attribute = null,
    ) {
    }

    /**
     * The element id, or null for a ref by slug or path ("news/launch").
     *
     * WHY digits only rather than is_numeric() as Craft's parser uses: "1.5"
     * or "1e3" pass is_numeric yet name no element, and a slug ref is checked
     * by a different query, so anything but a plain positive integer is
     * treated as "not an id" and counted as unchecked by the caller.
     */
    public function id(): ?int {
        if (!ctype_digit($this->ref)) {
            return null;
        }

        $id = (int) $this->ref;

        return $id > 0 ? $id : null;
    }
}
