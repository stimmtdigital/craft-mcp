<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use ParseError;
use Psy\CodeCleaner;
use Psy\Exception\ParseErrorException;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\OutputMode;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Ansi;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Throwable;

/**
 * Tinker tool for executing PHP code within Craft context.
 *
 * Uses PsySH's CodeCleaner for parsing, executes in Craft's context.
 *
 * SECURITY WARNING: This tool uses eval() with a blocklist approach.
 * The blocklist can be bypassed (e.g., call_user_func, variable functions).
 * Only enable in trusted environments. Consider this a convenience tool
 * for development, NOT a secure sandbox.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class TinkerTools {
    /**
     * Patterns blocked for basic security. NOT comprehensive - can be bypassed.
     */
    private const array BLOCKED_PATTERNS = [
        '/\bexec\s*\(/i',
        '/\bshell_exec\s*\(/i',
        '/\bsystem\s*\(/i',
        '/\bpassthru\s*\(/i',
        '/\bpopen\s*\(/i',
        '/\bproc_open\s*\(/i',
        '/\bpcntl_/i',
        '/\bposix_/i',
        '/\bunlink\s*\(/i',
        '/\brmdir\s*\(/i',
        '/\bfile_put_contents\s*\(/i',
        '/\bfwrite\s*\(/i',
        '/\brename\s*\(/i',
        '/\bcopy\s*\(/i',
        '/\bmove_uploaded_file\s*\(/i',
        '/\beval\s*\(/i',
        '/\bcreate_function\s*\(/i',
    ];

    private ?CodeCleaner $cleaner = null;

    /**
     * Execute arbitrary PHP code within Craft's application context.
     *
     * WARNING: Uses blocklist-based security which can be bypassed.
     * Only use in trusted development environments.
     */
    #[McpTool(
        name: 'tinker',
        description: 'Execute PHP code within Craft CMS context. WARNING: Basic blocklist security only - not a secure sandbox. For development use only. Has access to Craft::$app and all services.',
    )]
    #[McpToolMeta(category: ToolCategory::DEBUGGING, dangerous: true)]
    public function tinker(
        string $code,
        #[CompletionProvider(enum: OutputMode::class)]
        string $output = 'dump',
    ): TextContent {
        $outputMode = OutputMode::tryFrom($output) ?? OutputMode::DUMP;

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                return $this->response(
                    $code,
                    $this->formatError('SecurityError', 'Code contains blocked function. Shell commands, file writes, and eval are not allowed.'),
                );
            }
        }

        try {
            $cleaner = $this->getCodeCleaner();
            $cleanedCode = $cleaner->clean([$code]);

            // Make useful variables available
            $app = Craft::$app;

            ob_start();
            $result = eval($cleanedCode);
            $stdout = ob_get_clean();

            return $this->response(
                $code,
                $this->formatOutput($result, $outputMode),
                $stdout ?: null,
            );
        } catch (ParseErrorException|ParseError $e) {
            ob_end_clean();

            return $this->response($code, $this->formatError('ParseError', $e->getMessage()));
        } catch (Throwable $e) {
            ob_end_clean();

            return $this->response($code, $this->formatError($e::class, $e->getMessage(), $e));
        }
    }

    /**
     * Build the complete response.
     */
    private function response(string $code, string $result, ?string $stdout = null): TextContent {
        $output = $this->formatInput($code);

        if ($stdout !== null) {
            $output .= $stdout . "\n";
        }

        $output .= $result;

        return new TextContent($output);
    }

    /**
     * Format the input line.
     */
    private function formatInput(string $code): string {
        return Ansi::dim(Ansi::PROMPT) . ' ' . Ansi::dim($code) . "\n";
    }

    /**
     * Format the output line.
     */
    private function formatOutput(mixed $value, OutputMode $mode): string {
        $formatted = trim($this->formatResult($value, $mode));

        return Ansi::dim(Ansi::RESULT) . ' ' . $formatted;
    }

    /**
     * Format an error.
     */
    private function formatError(string $type, string $message, ?Throwable $e = null): string {
        $shortType = str_contains($type, '\\') ? substr($type, strrpos($type, '\\') + 1) : $type;

        // Strip internal eval noise from error messages
        $message = preg_replace('/, called in .+eval\(\)\'d code on line \d+/', '', $message) ?? $message;

        $output = Ansi::red(Ansi::ERROR . ' ' . $shortType . ':') . ' ' . $message;

        if ($e !== null) {
            $location = $this->getUsefulLocation($e);
            if ($location !== null) {
                $output .= "\n" . Ansi::gray('   at ' . $location);
            }
        }

        return $output;
    }

    /**
     * Get a useful error location, filtering out internal noise.
     */
    private function getUsefulLocation(Throwable $e): ?string {
        // Check exception's own file first
        $file = $e->getFile();
        $line = $e->getLine();

        // Skip if it's eval'd code or internal
        if ($this->isInternalFile($file)) {
            // Look through trace for first useful entry
            foreach ($e->getTrace() as $frame) {
                $frameFile = $frame['file'] ?? '';
                if ($frameFile !== '' && !$this->isInternalFile($frameFile)) {
                    return basename($frameFile) . ':' . ($frame['line'] ?? 0);
                }
            }

            return null;
        }

        return basename($file) . ':' . $line;
    }

    /**
     * Check if a file path is internal (should be filtered from traces).
     */
    private function isInternalFile(string $file): bool {
        return str_contains($file, 'eval')
            || str_contains($file, 'TinkerTools')
            || str_contains($file, 'mcp/sdk')
            || str_contains($file, 'mcp-server');
    }

    /**
     * Get the PsySH CodeCleaner for proper PHP parsing.
     */
    private function getCodeCleaner(): CodeCleaner {
        if ($this->cleaner === null) {
            $this->cleaner = new CodeCleaner();
        }

        return $this->cleaner;
    }

    /**
     * Format a value based on output mode.
     */
    private function formatResult(mixed $value, OutputMode $mode): string {
        return match ($mode) {
            OutputMode::DUMP => $this->formatDump($value),
            OutputMode::JSON => $this->formatJson($value),
            OutputMode::RAW => $this->formatRaw($value),
            OutputMode::PRINT_R => $this->formatPrintR($value),
        };
    }

    /**
     * Format using VarDumper (colored).
     */
    private function formatDump(mixed $value): string {
        $cloner = new VarCloner();
        $dumper = new CliDumper();
        $dumper->setColors(true);

        return $dumper->dump($cloner->cloneVar($value), true) ?? '';
    }

    /**
     * Format as JSON.
     */
    private function formatJson(mixed $value): string {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '(JSON encoding failed)';
    }

    /**
     * Format using var_export.
     */
    private function formatRaw(mixed $value): string {
        return var_export($value, true);
    }

    /**
     * Format using print_r.
     */
    private function formatPrintR(mixed $value): string {
        return print_r($value, true);
    }
}
