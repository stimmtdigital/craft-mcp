<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\text;

use stimmt\craft\Mcp\enums\OutputMode;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Throwable;

/**
 * Lays out one tinker exchange the way a REPL would: the input echoed back,
 * then the value, rendered in the mode the caller asked for.
 *
 * WHY it is not on the tool: this is presentation, and it was most of
 * TinkerTools. The tool's own job is running code safely, which is the part
 * worth reading when someone opens that file.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Transcript {
    /**
     * Format the input line.
     */
    public function input(string $code): string {
        return Ansi::dim(Ansi::prefixLines(Ansi::PROMPT, $code)) . "\n";
    }

    /**
     * Format the output line.
     */
    public function output(mixed $value, OutputMode $mode): string {
        $formatted = trim($this->result($value, $mode));

        return Ansi::prefixLines(Ansi::dim(Ansi::RESULT), $formatted);
    }

    /**
     * Format an error.
     */
    public function error(string $type, string $message, ?Throwable $e = null): string {
        $shortType = str_contains($type, '\\') ? substr($type, strrpos($type, '\\') + 1) : $type;

        // Strip internal eval noise from error messages
        $message = preg_replace('/, called in .+eval\(\)\'d code on line \d+/', '', $message) ?? $message;

        $output = Ansi::red(Ansi::ERROR . ' ' . $shortType . ':') . ' ' . $message;

        $location = $e !== null ? $this->getUsefulLocation($e) : null;
        if ($location !== null) {
            $output .= "\n" . Ansi::gray('   at ' . $location);
        }

        return $output;
    }

    /**
     * Format a value based on output mode.
     */
    private function result(mixed $value, OutputMode $mode): string {
        return match ($mode) {
            OutputMode::DUMP => $this->dump($value),
            OutputMode::JSON => $this->json($value),
            OutputMode::RAW => $this->raw($value),
            OutputMode::PRINT_R => $this->printR($value),
        };
    }

    /**
     * Format using VarDumper (colored).
     */
    private function dump(mixed $value): string {
        $cloner = new VarCloner();
        $dumper = new CliDumper();
        $dumper->setColors(true);

        return $dumper->dump($cloner->cloneVar($value), true) ?? '';
    }

    /**
     * Format as JSON.
     */
    private function json(mixed $value): string {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '(JSON encoding failed)';
    }

    /**
     * Format using var_export.
     */
    private function raw(mixed $value): string {
        return var_export($value, true);
    }

    /**
     * Format using print_r.
     */
    private function printR(mixed $value): string {
        return print_r($value, true);
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
}
