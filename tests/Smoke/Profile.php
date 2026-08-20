<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use RuntimeException;

/**
 * One identity on one transport, with its own baseline.
 *
 * WHY the harness is a set of profiles rather than a single run: the plugin
 * answers on two transports and under three token scopes, and the differences
 * between them are exactly where the defects live. A tool that works on stdio
 * and destroys its own response over HTTP looks fine to a single-transport
 * harness, and a scope boundary that hides a tool from the catalogue while
 * still running it when called looks fine to a harness that only reads the
 * catalogue. Each profile records its own baseline, so drift is attributed to
 * the connection that produced it.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Profile {
    public const string STDIO = 'stdio';

    public const string HTTP = 'http';

    public const string SCOPE_READONLY = 'readonly';

    public const string SCOPE_CONTENT = 'content';

    public const string SCOPE_FULL = 'full';

    public function __construct(
        public readonly string $name,
        public readonly string $transport,
        public readonly ?string $scope,
        public readonly string $baseline,
    ) {
    }

    /**
     * The stdio profile keeps the original baseline filename: it is the guard
     * that already exists, and renaming it would throw away the history it
     * carries.
     *
     * @return array<string, self>
     */
    public static function all(): array {
        $profiles = [
            new self('stdio-full', self::STDIO, null, 'stdio.json'),
            new self('http-full', self::HTTP, self::SCOPE_FULL, 'http-full.json'),
            new self('http-content', self::HTTP, self::SCOPE_CONTENT, 'http-content.json'),
            new self('http-readonly', self::HTTP, self::SCOPE_READONLY, 'http-readonly.json'),
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
