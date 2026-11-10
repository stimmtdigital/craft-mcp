<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use JsonException;
use RuntimeException;

/**
 * The Craft install the harness drives, and the one way it runs anything there.
 *
 * WHY a command prefix rather than a path: v2 has no server of its own, so the
 * harness no longer needs to sit inside the Craft project. It needs three
 * things from the install (an admin to serve stdio as, a token per HTTP
 * profile, and the fixture) and all three are a `php` run in the Craft root.
 * The prefix says how to get there:
 *
 *   CRAFT_MCP_SMOKE_CRAFT="cd ~/Projects/site && ddev exec --dir /var/www/html"
 *
 * Without it the harness walks up from this file to the nearest Craft root, so
 * a checkout placed inside a project still works the way v1 did.
 *
 * The scripts under inside/ are piped into `php` on stdin rather than copied
 * into the project, so nothing has to be synced into a container and a minted
 * token only ever exists in a pipe and in this process's memory.
 */
final class Install {
    private const string PREFIX_VARIABLE = 'CRAFT_MCP_SMOKE_CRAFT';

    /** @var array<string, mixed>|null */
    private static ?array $info = null;

    /**
     * The command that runs $tail in the Craft root.
     */
    public static function command(string $tail): string {
        return self::prefix() . ' ' . $tail;
    }

    /**
     * Admin email, MCP endpoint, editions and primary site, asked once.
     *
     * @return array<string, mixed>
     */
    public static function info(): array {
        return self::$info ??= self::script('console.php', ['info']);
    }

    public static function admin(): string {
        $admin = self::info()['admin'] ?? null;
        if (!is_string($admin) || $admin === '') {
            throw new RuntimeException('The install reported no admin user.');
        }

        return $admin;
    }

    /**
     * Pipes inside/$file into `php` in the Craft root and decodes the one JSON
     * object it answers with. Its stderr is passed through, so a failure says
     * why; the scripts never write a secret there.
     *
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    public static function script(string $file, array $arguments): array {
        $path = __DIR__ . '/inside/' . $file;
        $command = self::command('php -- ' . implode(' ', array_map('escapeshellarg', $arguments)));

        $process = proc_open($command, [0 => ['file', $path, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Could not run {$file} in the Craft install.");
        }

        $output = (string) stream_get_contents($pipes[1]);
        $errors = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0) {
            throw new RuntimeException("{$file} {$arguments[0]} failed ({$status}): " . Redact::text($errors));
        }

        return self::decode($output, $file);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $output, string $file): array {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        try {
            $decoded = json_decode($last, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("{$file} answered without JSON: " . Redact::text(substr($output, 0, 300)));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("{$file} answered with something other than an object.");
        }

        return $decoded;
    }

    private static function prefix(): string {
        $configured = getenv(self::PREFIX_VARIABLE);
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return 'cd ' . escapeshellarg(self::root()) . ' &&';
    }

    /**
     * The Craft installation above this checkout, for when no prefix says
     * where it is.
     */
    private static function root(): string {
        $directory = __DIR__;

        for ($level = 0; $level < 10; $level++) {
            $directory = dirname($directory);
            if (is_file($directory . '/bootstrap.php') && is_file($directory . '/craft')) {
                return $directory;
            }
        }

        throw new RuntimeException('No Craft install found above ' . __DIR__ . '. Export ' . self::PREFIX_VARIABLE . ' with the command that reaches one, e.g. "cd ~/site && ddev exec --dir /var/www/html".');
    }
}
