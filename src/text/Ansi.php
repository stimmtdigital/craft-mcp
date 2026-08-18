<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\text;

/**
 * The escape sequences themselves, and nothing more.
 *
 * Whether any of this is emitted is Palette's call, never this class's and
 * never a tool's. Only what is actually used is kept here: an unused colour
 * constant is a suggestion to reach past the palette.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Ansi {
    // Colors
    public const string RED = "\033[31m";

    public const string YELLOW = "\033[33m";

    public const string CYAN = "\033[36m";

    public const string GRAY = "\033[90m";

    // Styles
    public const string BOLD = "\033[1m";

    public const string DIM = "\033[2m";

    // Reset
    public const string RESET = "\033[0m";

    // Symbols
    public const string PROMPT = '>';

    public const string RESULT = '=';

    public const string ERROR = '!';

    /**
     * Wrap text in dim style.
     */
    public static function dim(string $text): string {
        return self::DIM . $text . self::RESET;
    }

    /**
     * Wrap text in red color.
     */
    public static function red(string $text): string {
        return self::RED . $text . self::RESET;
    }

    /**
     * Wrap text in gray color.
     */
    public static function gray(string $text): string {
        return self::GRAY . $text . self::RESET;
    }

    /**
     * Strip all ANSI codes from text.
     */
    public static function strip(string $text): string {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }

    /**
     * Prefix multiline content with a symbol, indenting continuation lines.
     *
     * Example: prefixLines('>', "line1\nline2") => "> line1\n  line2"
     */
    public static function prefixLines(string $prefix, string $content): string {
        $lines = explode("\n", $content);
        $first = array_shift($lines);
        $visualWidth = mb_strlen(self::strip($prefix));
        $indent = str_repeat(' ', $visualWidth + 1);
        $continuation = array_map(static fn (string $line): string => $indent . $line, $lines);

        return $prefix . ' ' . $first . ($continuation !== [] ? "\n" . implode("\n", $continuation) : '');
    }
}
