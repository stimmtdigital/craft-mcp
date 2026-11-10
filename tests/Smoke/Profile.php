<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use RuntimeException;

/**
 * One identity on one transport, with its own baseline.
 *
 * WHY the harness is a set of profiles rather than a single run: since v2 the
 * plugin is no server. It registers its tools into Craft's, and Craft decides
 * who sees which: a tool with readOnlyHint needs `mcp:read`, any other tool
 * `mcp:write`, and a method marked RequiresAdmin needs `mcp:admin` and an
 * admin user. stdio carries no token at all, only the user it is served as.
 * The differences between those connections are exactly where a defect in the
 * plugin's annotations would live, so each records its own baseline and drift
 * is attributed to the connection that produced it.
 *
 * Every profile is the same admin user. What differs is the scope the
 * connection carries, so a difference in the catalogue is Craft's scope filter
 * reading the plugin's annotations, and nothing else.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Profile {
    public const string STDIO = 'stdio';

    public const string HTTP = 'http';

    public const string READ = 'mcp:read';

    public const string WRITE = 'mcp:write';

    public const string ADMIN = 'mcp:admin';

    /**
     * @param list<string> $scopes the token's scopes; empty on stdio, which
     *                             carries none
     */
    public function __construct(
        public readonly string $name,
        public readonly string $transport,
        public readonly string $level,
        public readonly array $scopes,
        public readonly string $baseline,
    ) {
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array {
        $profiles = [
            new self('stdio-admin', self::STDIO, 'admin', [], 'stdio-admin.json'),
            new self('http-read', self::HTTP, 'read', [self::READ], 'http-read.json'),
            new self('http-write', self::HTTP, 'write', [self::READ, self::WRITE], 'http-write.json'),
            new self('http-admin', self::HTTP, 'admin', [self::READ, self::WRITE, self::ADMIN], 'http-admin.json'),
        ];

        $keyed = [];
        foreach ($profiles as $profile) {
            $keyed[$profile->name] = $profile;
        }

        return $keyed;
    }

    public static function named(string $name): self {
        $profile = self::all()[$name] ?? null;
        if (!$profile instanceof self) {
            throw new RuntimeException("Unknown profile '{$name}'. Known: " . implode(', ', array_keys(self::all())));
        }

        return $profile;
    }

    public function isHttp(): bool {
        return $this->transport === self::HTTP;
    }
}
