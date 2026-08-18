<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use ParseError;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psy\CodeCleaner;
use Psy\Exception\ParseErrorException;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\OutputMode;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\MutexGuard;
use stimmt\craft\Mcp\support\Transcript;
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
        // Unbounded buffer teardown: the server's stdout shield buffer is
        // non-removable, so a while-loop draining ob_get_level() never ends.
        '/\bwhile\s*\(\s*ob_get_level\s*\(/i',
    ];

    private ?CodeCleaner $cleaner = null;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly Transcript $transcript = new Transcript(),
    ) {
    }

    /**
     * Execute arbitrary PHP code within Craft's application context.
     *
     * WARNING: Uses blocklist-based security which can be bypassed.
     * Only use in trusted development environments.
     */
    #[McpTool(
        name: 'tinker',
        description: 'Execute PHP code within Craft CMS context. Prefer a specific tool when one exists (content, schema, database tools); reach for tinker when none can express the job, such as cross-entry computation. WARNING: Basic blocklist security only - not a secure sandbox. For development use only. Has access to Craft::$app and all services.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::DEBUGGING, dangerous: true)]
    public function tinker(
        string $code,
        // Typed as the enum rather than a string carrying a CompletionProvider:
        // MCP has no completion channel for tool arguments, so that attribute
        // was dead and the generated schema advertised neither the allowed
        // values nor a description. An unrecognised value silently became dump.
        #[Schema(description: 'How the return value is rendered.')]
        OutputMode $output = OutputMode::DUMP,
        ?RequestContext $context = null,
    ): TextContent {
        // SafeExecution is the outer safety net for unexpected failures
        // (e.g. CodeCleaner instantiation). The inner try/catch handles
        // expected errors with REPL-style formatting.
        $outputMode = $output;

        $this->logger->debug('Tinker executing', ['code' => mb_substr($code, 0, 200)]);

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (!preg_match($pattern, $code)) {
                continue;
            }

            $this->logger->debug('Tinker blocked by security pattern', ['pattern' => $pattern]);
            $context?->getClientLogger()?->warning("Tinker code rejected by security pattern: {$pattern}");

            return $this->response(
                $code,
                $this->transcript->error('SecurityError', 'Code contains a blocked pattern. Shell commands, file writes, eval, and unbounded output-buffer teardown loops are not allowed.'),
            );
        }

        $context?->getClientLogger()?->info('Tinker code accepted for execution');
        $context?->getClientLogger()?->debug("Tinker code: {$code}");

        $baseLevel = ob_get_level();

        try {
            $cleaner = $this->getCodeCleaner();
            $cleanedCode = $cleaner->clean([$code]);

            $app = Craft::$app;

            ob_start();
            $result = eval($cleanedCode);
            $stdout = $this->drainCapturedOutput($baseLevel);

            $this->logger->debug('Tinker completed');
            $context?->getClientLogger()?->info('Tinker execution completed');

            return $this->response(
                $code,
                $this->transcript->output($result, $outputMode),
                $stdout,
            );
        } catch (ParseErrorException|ParseError $e) {
            $this->drainCapturedOutput($baseLevel);

            $this->logger->debug('Tinker caught error', ['error' => $e->getMessage()]);
            $context?->getClientLogger()?->warning('Tinker execution failed: ' . $e::class);

            return $this->response($code, $this->transcript->error('ParseError', $e->getMessage()));
        } catch (Throwable $e) {
            $this->drainCapturedOutput($baseLevel);

            $this->logger->debug('Tinker caught error', ['error' => $e->getMessage()]);
            $context?->getClientLogger()?->warning('Tinker execution failed: ' . $e::class);

            return $this->response($code, $this->transcript->error($e::class, $e->getMessage(), $e));
        } finally {
            MutexGuard::releaseAll();
        }
    }

    /**
     * Collect and close every buffer opened above the capture baseline.
     *
     * User code may have closed the capture buffer or opened extra ones,
     * so this only drains buffers above $baseLevel. Outer buffers such as
     * the stdout shield in bin/mcp-server are never touched, and a
     * non-removable buffer above the baseline stops the drain instead of
     * looping forever.
     */
    private function drainCapturedOutput(int $baseLevel): ?string {
        $stdout = '';

        while (ob_get_level() > $baseLevel) {
            $flags = (int) (ob_get_status()['flags'] ?? 0);
            if (($flags & PHP_OUTPUT_HANDLER_REMOVABLE) === 0) {
                break;
            }

            $stdout = ob_get_clean() . $stdout;
        }

        return $stdout !== '' ? $stdout : null;
    }

    /**
     * Build the complete response.
     */
    private function response(string $code, string $result, ?string $stdout = null): TextContent {
        $output = $this->transcript->input($code);

        if ($stdout !== null) {
            $output .= $stdout . "\n";
        }

        $output .= $result;

        return new TextContent($output);
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
