<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use stimmt\craft\Mcp\Mcp;

/**
 * The one place that decides whether tool output carries ANSI colour.
 *
 * ANSI escapes are worth their bytes in a human terminal and are pure noise to
 * a model: they arrive at an agent client as literal `[2m` sequences that cost
 * tokens and carry no meaning. The usual consumer of this server is a model,
 * so colour is OFF by default and the site owner opts in with the `colorOutput`
 * setting.
 *
 * Roles, not colours, are the public API: callers ask for a heading or a
 * warning and never learn which escape backs it, so the mapping can change in
 * one place. Ansi stays the escape vocabulary; this class stays the policy.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Palette {
    public function __construct(private bool $enabled = false) {
    }

    /**
     * The install's configured policy. Only wiring code (the server factory,
     * a tool assembling its own formatter) calls this; renderers take the
     * palette they are given so they stay testable without Craft.
     */
    public static function fromSettings(): self {
        return new self(Mcp::settings()->colorOutput);
    }

    public function enabled(): bool {
        return $this->enabled;
    }

    /**
     * Column headers and section titles.
     */
    public function heading(string $text): string {
        return $this->paint(Ansi::BOLD, $text);
    }

    /**
     * Keys in a key-value block.
     */
    public function key(string $text): string {
        return $this->paint(Ansi::CYAN, $text);
    }

    /**
     * Structural chrome and absent-value markers.
     */
    public function muted(string $text): string {
        return $this->paint(Ansi::DIM, $text);
    }

    /**
     * Secondary detail that should read as background.
     */
    public function subtle(string $text): string {
        return $this->paint(Ansi::GRAY, $text);
    }

    public function error(string $text): string {
        return $this->paint(Ansi::RED, $text);
    }

    public function warning(string $text): string {
        return $this->paint(Ansi::YELLOW, $text);
    }

    private function paint(string $style, string $text): string {
        if (!$this->enabled) {
            return $text;
        }

        return $style . $text . Ansi::RESET;
    }
}
