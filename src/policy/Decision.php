<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\policy;

/**
 * What the Gate says about one element on one connection.
 *
 * WHY an object rather than a bool: a later axis will need a third answer that
 * is neither "show it" nor "hide it", where the element stays visible with a
 * substituted schema and handler. Adding that is a new named constructor and a
 * new case here; with a bool it would be a signature change at every caller,
 * and there is no version of that which stays a single chokepoint. The reason
 * is also carried, because "why did this tool vanish" is otherwise answerable
 * only by re-deriving the whole decision by hand.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Decision {
    private function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public ?string $label = null,
        public ?string $notice = null,
    ) {
    }

    public static function keep(): self {
        return new self(true);
    }

    public static function deny(string $reason): self {
        return new self(false, $reason);
    }

    /**
     * The third answer the class comment promised: the element stays listed,
     * but with $label marking its description and a handler that answers
     * $notice instead of doing the work.
     *
     * `allowed` is false, because the tool may not do what it advertises. The
     * caller that registers elements is the one that has to ask substitutes()
     * first; anything that only counts what a connection can really call is
     * right to treat this as a refusal.
     */
    public static function substitute(string $reason, string $label, string $notice): self {
        return new self(false, $reason, $label, $notice);
    }

    public function substitutes(): bool {
        return $this->notice !== null;
    }
}
