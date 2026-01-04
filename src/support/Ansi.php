<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

/**
 * ANSI terminal formatting helper.
 *
 * Provides consistent terminal styling across all MCP tools.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Ansi {
    // Colors
    public const string RED = "\033[31m";

    public const string GREEN = "\033[32m";

    public const string YELLOW = "\033[33m";

    public const string BLUE = "\033[34m";

    public const string MAGENTA = "\033[35m";

    public const string CYAN = "\033[36m";

    public const string WHITE = "\033[37m";

    public const string GRAY = "\033[90m";

    // Styles
    public const string BOLD = "\033[1m";

    public const string DIM = "\033[2m";

    public const string ITALIC = "\033[3m";

    public const string UNDERLINE = "\033[4m";

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
     * Wrap text in bold style.
     */
    public static function bold(string $text): string {
        return self::BOLD . $text . self::RESET;
    }

    /**
     * Wrap text in red color.
     */
    public static function red(string $text): string {
        return self::RED . $text . self::RESET;
    }

    /**
     * Wrap text in green color.
     */
    public static function green(string $text): string {
        return self::GREEN . $text . self::RESET;
    }

    /**
     * Wrap text in yellow color.
     */
    public static function yellow(string $text): string {
        return self::YELLOW . $text . self::RESET;
    }

    /**
     * Wrap text in cyan color.
     */
    public static function cyan(string $text): string {
        return self::CYAN . $text . self::RESET;
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
}
