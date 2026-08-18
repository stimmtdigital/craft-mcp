<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use Throwable;

/**
 * The scope boundary, tested by calling across it.
 *
 * WHY calling rather than reading the catalogue: hiding a tool from tools/list
 * is presentation, and presentation is not a security boundary. A readonly
 * connection that omits `tinker` from its listing but still runs it when asked
 * by name is a privilege escalation, and it looks identical to a correct server
 * in any snapshot that only records what was advertised. So each scope names
 * the tools that must be outside it and calls them with arguments that would
 * succeed if the tool ran. A result instead of a refusal is a violation, and
 * the run fails.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Boundary {
    /**
     * Tools each scope must refuse. Chosen across the two axes the scope
     * predicate uses: a dangerous content write (allowed under content, denied
     * under readonly) and dangerous non-content tools (denied under both).
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const array OUTSIDE = [
        Profile::SCOPE_READONLY => [
            'create_entry' => ['section' => Plan::SECTION, 'type' => Plan::ENTRY_TYPE, 'title' => 'Boundary probe'],
            'tinker' => ['code' => 'return 1 + 1;'],
            'run_query' => ['sql' => 'SELECT id FROM elements ORDER BY id LIMIT 1'],
        ],
        Profile::SCOPE_CONTENT => [
            'tinker' => ['code' => 'return 1 + 1;'],
            'run_query' => ['sql' => 'SELECT id FROM elements ORDER BY id LIMIT 1'],
            'clear_caches' => [],
        ],
        Profile::SCOPE_FULL => [],
    ];

    /**
     * @param array<string, mixed> $tools the advertised catalogue
     * @return array<string, mixed>|null null when the connection carries no
     *                                   scope at all, which is stdio
     */
    public static function of(Client $client, Profile $profile, array $tools): ?array {
        if ($profile->scope === null) {
            return null;
        }

        $outside = [];
        $violations = [];
        foreach (self::OUTSIDE[$profile->scope] ?? [] as $tool => $arguments) {
            $probe = self::probe($client, $tool, $arguments, isset($tools[$tool]));
            $outside[$tool] = $probe;

            if ($probe['refused'] !== true) {
                $violations[] = "{$tool} is outside the {$profile->scope} scope but the call was not refused ({$probe['how']})";
            }
        }

        return ['granted' => $profile->scope, 'advertised' => count($tools), 'outside' => $outside, 'violations' => $violations];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{advertised: bool, refused: bool, how: string}
     */
    private static function probe(Client $client, string $tool, array $arguments, bool $advertised): array {
        try {
            $envelope = $client->callTool($tool, $arguments);
        } catch (Throwable $exception) {
            // The transport itself refused to carry the call. Not a leak, but
            // not a refusal we can attribute to the scope either, so it is
            // recorded as what it is.
            return ['advertised' => $advertised, 'refused' => false, 'how' => 'crashed: ' . $exception->getMessage()];
        }

        return self::verdict($envelope, $advertised);
    }

    /**
     * A JSON-RPC error is the SDK refusing to route a call to a tool it does
     * not have. An isError result naming the tool as unavailable is the same
     * refusal phrased differently. Anything else means the tool ran.
     *
     * @param array<string, mixed> $envelope
     * @return array{advertised: bool, refused: bool, how: string}
     */
    private static function verdict(array $envelope, bool $advertised): array {
        $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : null;
        if ($error !== null) {
            return ['advertised' => $advertised, 'refused' => true, 'how' => 'protocol-error ' . ($error['code'] ?? '?')];
        }

        $result = is_array($envelope['result'] ?? null) ? $envelope['result'] : [];
        if (($result['isError'] ?? false) !== true) {
            return ['advertised' => $advertised, 'refused' => false, 'how' => 'answered'];
        }

        $text = self::text($result);

        return [
            'advertised' => $advertised,
            'refused' => preg_match('/not (found|available|registered)|unknown tool/i', $text) === 1,
            'how' => 'tool-error',
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function text(array $result): string {
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $first = is_array($content[0] ?? null) ? $content[0] : [];

        return is_string($first['text'] ?? null) ? $first['text'] : '';
    }
}
