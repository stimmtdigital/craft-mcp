<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use Throwable;

/**
 * The scope boundary, tested by calling across it.
 *
 * WHY calling rather than reading the catalogue: hiding a tool from tools/list
 * is presentation, and presentation is not a security boundary. A read-only
 * token that omits `stimmt_tinker` from its listing but still runs it when
 * asked by name is a privilege escalation, and it looks identical to a correct
 * server in any snapshot that only records what was advertised. So each scope
 * names the tools that must be outside it and calls them with arguments that
 * would succeed if the tool ran. A result instead of a refusal is a violation,
 * and the run fails.
 *
 * In v2 the boundary is Craft's: it reads the plugin's annotations (readOnlyHint
 * means mcp:read, anything else mcp:write, RequiresAdmin means mcp:admin) and
 * builds the server's registry from what survives. What this pins is that the
 * plugin's annotations put its dangerous tools on the right side of it.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Boundary {
    /** The answer of a server that has no such tool in its registry. */
    private const string UNKNOWN_TOOL = '/\b(tool|capability)\b.{0,80}\b(not found|unknown|not registered|not available)\b|\bunknown tool\b/i';

    /**
     * Tools each profile must refuse, per the two axes Craft's scope check
     * uses: a write that is not admin (publishing, outside mcp:read) and the
     * admin tools (outside anything short of mcp:admin).
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const array OUTSIDE = [
        'http-read' => [
            'stimmt_drafts_publish' => ['id' => '{{fixture.id}}'],
            'stimmt_db_query' => ['sql' => 'SELECT id FROM elements ORDER BY id LIMIT 1'],
            'stimmt_tinker' => ['code' => 'return 1 + 1;'],
        ],
        'http-write' => [
            'stimmt_db_query' => ['sql' => 'SELECT id FROM elements ORDER BY id LIMIT 1'],
            'stimmt_tinker' => ['code' => 'return 1 + 1;'],
            'stimmt_config_get' => ['key' => 'general.devMode'],
        ],
        'http-admin' => [],
    ];

    /**
     * @param array<string, mixed> $tools the plugin's advertised catalogue
     * @param array<string, mixed> $captured values the plan captured, for probe
     *                                       arguments that name the fixture
     * @return array<string, mixed>|null null when the connection carries no
     *                                   scope at all, which is stdio
     */
    public static function of(Client $client, Profile $profile, array $tools, array $captured): ?array {
        if (!$profile->isHttp()) {
            return null;
        }

        $outside = [];
        $violations = [];
        foreach (self::OUTSIDE[$profile->name] ?? [] as $tool => $arguments) {
            $probe = self::probe($client, $tool, self::resolve($arguments, $captured), isset($tools[$tool]));
            $outside[$tool] = $probe;

            if ($probe['advertised']) {
                $violations[] = "{$tool} is outside {$profile->name} but tools/list advertises it";
            }

            if ($probe['refused'] !== true) {
                $violations[] = "{$tool} is outside {$profile->name} but the call was not refused ({$probe['how']})";
            }
        }

        $advertised = array_keys($tools);
        sort($advertised);

        return ['granted' => $profile->scopes, 'advertised' => $advertised, 'outside' => $outside, 'violations' => $violations];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $captured
     * @return array<string, mixed>
     */
    private static function resolve(array $arguments, array $captured): array {
        foreach ($arguments as $key => $value) {
            if (is_string($value) && preg_match('/^\{\{([^}]+)\}\}$/', $value, $matches) === 1) {
                // An uncaptured id still makes a well formed call: the probe is
                // about whether the server routes it, not what it finds.
                $arguments[$key] = $captured[$matches[1]] ?? 1;
            }
        }

        return $arguments;
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
            return ['advertised' => $advertised, 'refused' => false, 'how' => 'crashed: ' . Redact::text($exception->getMessage())];
        }

        return self::verdict($envelope, $advertised);
    }

    /**
     * A JSON-RPC error, or an isError result, saying the server has no such
     * tool is a refusal. Anything else means the tool ran, including a tool
     * error from the tool itself: "Entry 1 not found" is the tool answering,
     * which is exactly the leak this exists to catch, so the message must be
     * about the tool rather than about what the tool looked for.
     *
     * @param array<string, mixed> $envelope
     * @return array{advertised: bool, refused: bool, how: string}
     */
    private static function verdict(array $envelope, bool $advertised): array {
        $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : null;
        if ($error !== null) {
            $message = (string) ($error['message'] ?? '');

            return [
                'advertised' => $advertised,
                'refused' => preg_match(self::UNKNOWN_TOOL, $message) === 1,
                'how' => 'protocol-error ' . ($error['code'] ?? '?') . ': ' . self::stable($message),
            ];
        }

        $result = is_array($envelope['result'] ?? null) ? $envelope['result'] : [];
        if (($result['isError'] ?? false) !== true) {
            return ['advertised' => $advertised, 'refused' => false, 'how' => 'answered'];
        }

        $text = self::text($result);

        return [
            'advertised' => $advertised,
            'refused' => preg_match(self::UNKNOWN_TOOL, $text) === 1,
            'how' => 'tool-error: ' . self::stable($text),
        ];
    }

    /**
     * The refusal's wording, kept in the baseline because a change in how the
     * server refuses is worth seeing, with anything run specific taken out.
     */
    private static function stable(string $message): string {
        return Redact::text((string) preg_replace('/\d+/', 'N', substr($message, 0, 160)));
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
