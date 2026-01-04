<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use ParseError;
use Psy\CodeCleaner;
use Psy\Exception\ParseErrorException;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Serializer;
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
    public function tinker(string $code): array {
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                return [
                    'success' => false,
                    'error' => 'Code contains blocked function for security. Shell commands, file writes, and eval are not allowed.',
                ];
            }
        }

        try {
            // Use PsySH CodeCleaner for proper parsing and validation
            $cleaner = $this->getCodeCleaner();
            $cleanedCode = $cleaner->clean([$code]);

            // Make useful variables available
            $app = Craft::$app;

            // Capture output
            ob_start();

            // Execute the cleaned code
            $result = eval($cleanedCode);

            $output = ob_get_clean();

            return [
                'success' => true,
                'result' => Serializer::serialize($result),
                'output' => $output ?: null,
                'type' => gettype($result),
            ];
        } catch (ParseErrorException $e) {
            ob_end_clean();

            return [
                'success' => false,
                'error' => 'Parse error: ' . $e->getMessage(),
            ];
        } catch (ParseError $e) {
            ob_end_clean();

            return [
                'success' => false,
                'error' => 'Parse error: ' . $e->getMessage(),
                'line' => $e->getLine(),
            ];
        } catch (Throwable $e) {
            ob_end_clean();

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'trace' => array_slice(
                    array_map(fn ($t) => [
                        'file' => basename($t['file'] ?? ''),
                        'line' => $t['line'] ?? null,
                        'function' => $t['function'] ?? null,
                    ], $e->getTrace()),
                    0,
                    5,
                ),
            ];
        }
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
}
