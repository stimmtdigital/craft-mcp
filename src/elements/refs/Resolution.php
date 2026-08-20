<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

/**
 * What resolving one natural key produced.
 *
 * WHY an object rather than a nullable id: a key that matches nothing and a key
 * that matches several elements both have "no safe id", but they are different
 * mistakes and the caller has to say so. `{section, slug}` is not unique in a
 * structure section, where the same slug under two parents is ordinary content
 * modelling, so returning the first row silently related to whichever element
 * the database happened to hand back first. Carrying the ambiguity lets every
 * caller refuse instead of guess, and tell the agent which of the two problems
 * it actually has.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Resolution {
    private function __construct(
        public ?int $id,
        public bool $ambiguous,
    ) {
    }

    public static function none(): self {
        return new self(null, false);
    }

    public static function one(int $id): self {
        return new self($id, false);
    }

    public static function ambiguous(): self {
        return new self(null, true);
    }

    /**
     * How to explain this outcome to the agent, given the kind of thing the key
     * was addressing ("entry", "category", ...). It lives here so the callers
     * that report a failed resolution cannot drift into separate accounts of
     * the same condition, and so "matched nothing" never gets reported for a
     * key that in fact matched too much.
     */
    public function explain(string $subject): string {
        return $this->ambiguous
            ? "This key matches more than one {$subject}; it was left unresolved rather than guessed, so address it by id instead"
            : "No {$subject} matches this key";
    }
}
